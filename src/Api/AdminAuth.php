<?php
namespace App\Api;

final class AdminAuth {
    public function __construct(private Storage $db) {}
    public function create(string $username, string $password): void {
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/',$username) || strlen($password)<12 || strlen($password)>72)
            throw new \InvalidArgumentException('Username must have 3–64 letters/digits/._-; password must be 12–72 bytes.');
        $this->db->atomic(function () use ($username,$password) {
            $this->db->query('INSERT INTO admins(username,password_hash) VALUES(?,?) ON CONFLICT(username) DO UPDATE SET password_hash=excluded.password_hash,enabled=1',
                [$username,password_hash($password,PASSWORD_DEFAULT)]);
            $this->db->query('DELETE FROM sessions WHERE admin_id=(SELECT id FROM admins WHERE username=?)',[$username]);
        });
    }
    public function login(string $username, string $password, string $ip): array {
        if (!$this->db->consume('login-ip:'.hash('sha256',$ip),10,900) || !$this->db->consume('login-user:'.hash('sha256',strtolower($username)),10,900))
            throw new HttpError(429,'rate_limited','Too many sign-in attempts. Try again in 15 minutes.');
        $user=$this->db->query('SELECT * FROM admins WHERE username=? AND enabled=1',[$username])->fetch();
        // Fixed dummy hash keeps nonexistent-user checks comparably expensive.
        $valid=password_verify($password,$user['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
        if (!$user || !$valid) throw new HttpError(401,'invalid_credentials','Incorrect username or password.');
        $token=bin2hex(random_bytes(32)); $expires=time()+$this->db->config->sessionTtl;
        $this->db->atomic(function () use ($user,$token,$expires,$password) {
            $this->db->query('DELETE FROM sessions WHERE expires_at <= ?', [time()]);
            if (password_needs_rehash($user['password_hash'],PASSWORD_DEFAULT))
                $this->db->query('UPDATE admins SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$user['id']]);
            $this->db->query('INSERT INTO sessions VALUES(?,?,?)',[hash('sha256',$token),$user['id'],$expires]);
        });
        return ['token'=>$token,'expires_at'=>$expires,'user'=>['username'=>$user['username'],'role'=>'admin']];
    }
    public function authenticate(string $header): array {
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/',$header,$m)) throw new HttpError(401,'unauthorized','Admin sign-in required.');
        $user=$this->db->query('SELECT a.id,a.username,s.expires_at FROM sessions s JOIN admins a ON a.id=s.admin_id WHERE s.hash=? AND s.expires_at>? AND a.enabled=1',
            [hash('sha256',$m[1]),time()])->fetch();
        if (!$user) throw new HttpError(401,'unauthorized','Admin session expired or revoked.');
        $user['role']='admin'; return $user;
    }
    public function logout(string $header): void { $this->db->query('DELETE FROM sessions WHERE hash=?',[hash('sha256',substr($header,7))]); }
}
