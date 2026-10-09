<?php
namespace Aurelle\Services;
use Aurelle\Database as DB;
use Aurelle\Auth;
final class Documents {
    public static function upload(int $actor,array $file,?int $listing=null,?int $message=null): int {
        if ($listing) Platform::listing($listing,$actor,true);
        if ($message) { $m=DB::one('SELECT * FROM messages WHERE id=?',[$message]); if (!$m || $m['sender_id']!=$actor) Platform::fail('Attachment access denied.',403); Messaging::access($actor,$m['conversation_id']); }
        if (($file['error'] ?? 1)!==UPLOAD_ERR_OK || $file['size']>8*1024*1024 || $file['size']<1 || !is_uploaded_file($file['tmp_name'])) Platform::fail('Upload a file smaller than 8 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime,['application/pdf','image/jpeg','image/png','image/webp'])) Platform::fail('Only PDF, JPEG, PNG, and WebP are accepted.');
        $name=bin2hex(random_bytes(24)); $dir=ROOT.'/storage/documents'; if (!is_dir($dir)) mkdir($dir,0700,true);
        if (!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) Platform::fail('Could not save upload.',500);
        return DB::insert('INSERT INTO documents (owner_id,listing_id,message_id,original_name,storage_name,mime,size_bytes) VALUES (?,?,?,?,?,?,?)',[$actor,$listing,$message,substr(basename($file['name']),0,190),$name,$mime,$file['size']]);
    }
    public static function access(int $actor,int $id): array {
        $d=DB::one('SELECT * FROM documents WHERE id=?',[$id]); if (!$d) Platform::fail('Document not found.',404);
        if ($d['owner_id']==$actor || DB::one('SELECT user_id FROM document_shares WHERE document_id=? AND user_id=?',[$id,$actor])) return $d;
        if ($d['message_id']) { $m=DB::one('SELECT conversation_id FROM messages WHERE id=?',[$d['message_id']]); Messaging::access($actor,$m['conversation_id']); return $d; }
        Platform::fail('This document is private.',403);
    }
}
