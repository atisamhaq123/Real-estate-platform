<?php
declare(strict_types=1);
// Creates a dedicated test database; never runs tests in the configured application DB.
putenv('DB_DATABASE=aurelle_test_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)));
putenv('APP_ENV=local'); putenv('DEMO_PASSWORD=IsolatedDemoPass123!');
require dirname(__DIR__).'/app/bootstrap.php';
use Aurelle\Database as DB;
use Aurelle\Auth;
use Aurelle\Services\Platform as P;
use Aurelle\Services\Messaging as M;
use Aurelle\Services\Documents as D;
$tests=0;
function check(bool $condition,string $name): void { global $tests; if (!$condition) throw new RuntimeException('FAILED: '.$name); $tests++; echo "PASS: $name\n"; }
function denied(callable $action,string $name): void { try { $action(); } catch (RuntimeException $e) { check(in_array($e->getCode(),[401,403,404,409,422]),$name); return; } throw new RuntimeException('FAILED: '.$name.' was allowed'); }
ob_start(); require ROOT.'/bin/migrate.php'; require ROOT.'/bin/seed.php'; ob_end_clean();
echo 'Isolated database: '.env('DB_DATABASE').PHP_EOL;
check(DB::one('SELECT COUNT(*) n FROM listings')['n']==6,'Six seeded demonstration listings');
check(count(DB::all("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=? AND ENGINE<>'InnoDB'",[env('DB_DATABASE')]))===0,'Every table uses InnoDB');
$new=Auth::register(['name'=>'Permission Test','email'=>'test@example.invalid','password'=>'StrongTestPass123!','role'=>'admin']);
check(Auth::has('buyer',$new) && Auth::has('seller',$new) && !Auth::has('admin',$new) && !Auth::has('agent',$new),'Public registration cannot escalate privileges');
denied(fn()=>P::listing(1,$new,true),'Other seller cannot manage listing');
$draft=P::saveListing(3,['title'=>'Testing a thoughtful home','description'=>'A sufficiently detailed private property description for testing.','price'=>'123456.78','purpose'=>'sale','property_type'=>'Villa','bedrooms'=>3,'bathrooms'=>2,'floor_area'=>2000,'address'=>'Private 123 Street','city'=>'Test City','latitude'=>34.1234567,'longitude'=>-118.1234567,'location_privacy'=>'approximate']);
check(DB::one('SELECT price_minor FROM listings WHERE id=?',[$draft])['price_minor']==12345678,'Money stored in integer minor units');
denied(fn()=>P::listing($draft,$new),'Draft hidden from strangers');
P::transitionListing(3,$draft,'submitted'); denied(fn()=>P::transitionListing(3,$draft,'published'),'Seller cannot approve publication');
DB::query('UPDATE listings SET agent_id=4 WHERE id=?',[$draft]); P::transitionListing(1,$draft,'published');
check(P::search(['q'=>'Test City'])['total']===1,'Admin publication appears in public search');
$public=P::search(['q'=>'Test City'])['items'][0]; check($public['address']==='Address withheld' && $public['latitude']===34.12,'Search API redacts residential location');
P::saveListing(3,['id'=>$draft,'title'=>'Revised thoughtful home','description'=>'An updated sufficiently detailed property description for testing.','price'=>'123000','purpose'=>'sale','property_type'=>'Villa','bedrooms'=>3,'bathrooms'=>2,'floor_area'=>2000,'address'=>'Private 123 Street','city'=>'Test City','latitude'=>34.1234567,'longitude'=>-118.1234567]);
check(DB::one('SELECT status FROM listings WHERE id=?',[$draft])['status']==='submitted' && P::search(['q'=>'Test City'])['total']===0,'Published edits require review again');
P::transitionListing(1,$draft,'rejected','Please provide a clearer description'); check(DB::one('SELECT reason FROM listing_status_history WHERE listing_id=? ORDER BY id DESC LIMIT 1',[$draft])['reason']==='Please provide a clearer description','Revision reason retained');
P::transitionListing(3,$draft,'draft'); P::transitionListing(3,$draft,'submitted'); P::transitionListing(1,$draft,'published'); P::transitionListing(1,$draft,'archived'); denied(fn()=>P::listing($draft,$new),'Archived property hidden');
P::collection(2,1); denied(fn()=>P::collection($new,1),'Shared collection ownership boundary'); DB::query('INSERT INTO collection_memberships VALUES (1,?)',[$new]); P::collection($new,1); denied(fn()=>P::collection($new,1,true),'Collaborator cannot invite or remove members'); DB::query('DELETE FROM collection_memberships WHERE collection_id=1 AND user_id=?',[$new]); denied(fn()=>P::collection($new,1),'Removed collaborator loses access');
$start=(new DateTimeImmutable('+2 days',new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
$view=P::book(2,['listing_id'=>1,'starts_at'=>$start,'timezone'=>'UTC']);
denied(fn()=>P::book($new,['listing_id'=>1,'starts_at'=>$start,'timezone'=>'UTC']),'Concurrent viewing conflict rejected');
denied(fn()=>P::viewingState($new,$view,'confirmed'),'Stranger cannot change appointment'); P::viewingState(4,$view,'confirmed'); denied(fn()=>P::viewingState(4,$view,'completed'),'Future appointment cannot be completed'); P::viewingState(2,$view,'cancelled');
$proposal=P::proposal(2,['listing_id'=>1,'amount'=>'4000000','terms'=>'Subject to inspection and financing.']);
denied(fn()=>P::proposalState($new,$proposal,'accepted'),'Other buyer cannot access proposal'); P::proposalState(3,$proposal,'countered',['amount'=>'4100000','terms'=>'Subject to inspection; close in sixty days.']);
denied(fn()=>P::proposalState(3,$proposal,'accepted'),'Cannot accept own current proposal terms'); P::proposalState(2,$proposal,'accepted');
check(DB::one('SELECT COUNT(*) n FROM proposal_revisions WHERE proposal_id=?',[$proposal])['n']==2,'Proposal revision history is immutable'); check(DB::one('SELECT COUNT(*) n FROM transaction_checklists WHERE proposal_id=?',[$proposal])['n']==5,'Acceptance creates progress checklist'); check(DB::one('SELECT status FROM listings WHERE id=1')['status']==='published','Acceptance does not transfer ownership or complete sale');
denied(fn()=>M::access(1,1),'Ordinary admin cannot freely read private messages'); denied(fn()=>M::send($new,1,'Intrusion attempt','unique-intrusion-key123'),'Nonparticipant cannot send');
$message=M::send(2,1,'A genuine persisted test message','test-message-idempotency-1'); $duplicate=M::send(2,1,'A genuine persisted test message','test-message-idempotency-1'); check($message['id']==$duplicate['id'],'Duplicate sends produce one message'); denied(fn()=>M::send(2,1,'Changed duplicate content','test-message-idempotency-1'),'Reused key with changed content is rejected');
M::receipt(4,1,$message['id'],true); check(DB::one('SELECT read_at FROM message_receipts WHERE message_id=? AND user_id=4',[$message['id']])['read_at']!==null,'Recipient read receipt persisted');
DB::query('INSERT INTO blocks VALUES (4,2)'); denied(fn()=>M::send(2,1,'Blocked message','blocked-test-message1'),'Block enforced by server'); DB::query('DELETE FROM blocks WHERE user_id=4 AND blocked_user_id=2');
$token=M::token(2); check(M::resolve($token)===2,'Expiring socket token resolves'); DB::query('UPDATE realtime_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?',[hash('sha256',$token)]); check(M::resolve($token)===null,'Expired socket token rejected');
DB::query('UPDATE users SET suspended=1 WHERE id=2'); denied(fn()=>M::access(2,1),'Suspension revokes channel access'); DB::query('UPDATE users SET suspended=0 WHERE id=2');
DB::query('UPDATE listings SET agent_id=5 WHERE id=1'); denied(fn()=>M::access(4,1),'Reassigned agent loses old conversation access'); denied(fn()=>M::send(2,1,'Former agent message','former-assignment-test1'),'Former assignment channel stops accepting messages'); DB::query('UPDATE listings SET agent_id=4 WHERE id=1');
DB::query('DELETE FROM role_memberships WHERE user_id=4 AND role="agent"'); denied(fn()=>M::access(4,1),'Revoked agent role removes channel access'); DB::query('INSERT INTO role_memberships VALUES (4,"agent")');
$doc=DB::insert('INSERT INTO documents (owner_id,original_name,storage_name,mime,size_bytes) VALUES (2,"test.pdf","isolated-test-file","application/pdf",100)'); denied(fn()=>D::access(1,$doc),'Admin cannot download private customer document'); DB::query('INSERT INTO document_shares VALUES (?,4)',[$doc]); check(D::access(4,$doc)['id']==$doc,'Explicit document share works'); DB::query('DELETE FROM document_shares WHERE document_id=?',[$doc]); denied(fn()=>D::access(4,$doc),'Revoked document share denied');
$request=DB::insert('INSERT INTO service_requests (user_id,service_id,details) VALUES (2,1,"Photography consultation test")'); $invoice=P::quote(1,$request,50000); denied(fn()=>P::payment(2,$invoice,10000,'unauthorized-payment'),'Buyer cannot record their own payment'); $payment=P::payment(1,$invoice,50000,'test-payment-reference'); check(P::payment(1,$invoice,50000,'test-payment-reference')===$payment,'Duplicate payment events are idempotent'); denied(fn()=>P::payment(1,$invoice,1,'overpayment-test'),'Overpayment rejected');
$refund=P::refund(1,$payment,10000,'refund-test-reference','Partial photography service refund'); check(P::refund(1,$payment,10000,'refund-test-reference','Partial photography service refund')===$refund,'Duplicate refunds idempotent'); denied(fn()=>P::refund(1,$payment,45000,'refund-over-limit','Invalid excessive refund'),'Refund cannot exceed original payment'); check(DB::one('SELECT SUM(amount_minor) n FROM credits WHERE invoice_id=?',[$invoice])['n']==10000,'Credit history synchronized with refund');
try { DB::transaction(function(){ DB::query('UPDATE listings SET title="Must roll back" WHERE id=1'); throw new RuntimeException('Rollback deliberately'); }); } catch (RuntimeException) {}
check(DB::one('SELECT title FROM listings WHERE id=1')['title']==='The Bel Air Residence','Coordinated transactions actually roll back');
try { DB::query('INSERT INTO favorites VALUES (999999,1,UTC_TIMESTAMP())'); throw new RuntimeException('Foreign key not enforced'); } catch (PDOException) { check(true,'Database enforces foreign keys'); }
check(DB::one('SELECT COUNT(*) n FROM audit_events')['n']>10,'Workflow changes have actor audit history');
echo "$tests checks passed. The isolated database is retained for inspection.\n";
if (!is_dir(ROOT.'/tests/results')) mkdir(ROOT.'/tests/results',0700,true);
file_put_contents(ROOT.'/tests/results/database.txt',env('DB_DATABASE'));
