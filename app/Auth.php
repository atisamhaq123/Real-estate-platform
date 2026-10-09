<?php
namespace Aurelle;
final class Auth {
    public static function user(): ?array {
        $id=$_SESSION['user_id'] ?? null;
        return $id ? Database::one('SELECT * FROM users WHERE id=? AND suspended=0',[$id]) : null;
    }
    public static function requireUser(): array { $u=self::user(); if (!$u) throw new \RuntimeException('Please sign in to continue.',401); return $u; }
    public static function has(string $role, ?int $id=null): bool {
        $id ??= self::user()['id'] ?? 0;
        return (bool)Database::one('SELECT user_id FROM role_memberships WHERE user_id=? AND role=?',[$id,$role]);
    }
    public static function admin(): array { $u=self::requireUser(); if (!self::has('admin')) throw new \RuntimeException('Administrator access required.',403); return $u; }
    public static function csrf(): void { if (!hash_equals($_SESSION['csrf'],(string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) throw new \RuntimeException('Your session expired. Refresh and try again.',419); }
    public static function rate(string $key, int $limit, int $seconds): void {
        $bucket=(int)floor(time()/$seconds); $key=hash('sha256',$key);
        Database::query('INSERT INTO rate_limits (bucket_key,bucket,hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits=hits+1',[$key,$bucket]);
        $row=Database::one('SELECT hits FROM rate_limits WHERE bucket_key=? AND bucket=?',[$key,$bucket]);
        if ($row['hits']>$limit) throw new \RuntimeException('Too many attempts. Please try again later.',429);
    }
    public static function login(string $email,string $password): void {
        self::rate('login:'.($_SERVER['REMOTE_ADDR'] ?? 'cli'),10,600);
        $u=Database::one('SELECT * FROM users WHERE email=? AND suspended=0',[strtolower(trim($email))]);
        if (!$u || !password_verify($password,$u['password_hash'])) throw new \RuntimeException('Email or password is incorrect.',422);
        session_regenerate_id(true); $_SESSION['user_id']=$u['id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public static function register(array $data): int {
        $name=trim($data['name'] ?? ''); $email=strtolower(trim($data['email'] ?? '')); $password=$data['password'] ?? '';
        if (strlen($name)<2 || strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) throw new \RuntimeException('Enter your name, a valid email, and a password of at least 12 characters.',422);
        return Database::transaction(function() use ($name,$email,$password) {
            if (Database::one('SELECT id FROM users WHERE email=?',[$email])) throw new \RuntimeException('This email is already registered.',422);
            $id=Database::insert('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            foreach (['buyer','seller'] as $role) Database::query('INSERT INTO role_memberships VALUES (?,?)',[$id,$role]);
            return $id;
        });
    }
}
