<?php
declare(strict_types=1);

namespace SaleSync\Auth;

use SaleSync\Core\{ApiError, Database, Input, Session};
use SaleSync\Customer\AddressDetails;
use SaleSync\Customer\NotificationService;

final class AuthService
{
    public function __construct(private readonly Database $db, private readonly Mailer $mailer, private readonly int $minimumAge = 18) {}

    public function current(): ?array
    {
        if (!isset($_SESSION['user_id'])) { return null; }
        $user = $this->db->one('SELECT user_id,name,email,phone,birth_date,role,status,email_verified_at,created_at,auth_version FROM users WHERE user_id = ?', [$_SESSION['user_id']]);
        if ($user && $user['auth_version'] !== ($_SESSION['auth_version'] ?? 0)) { Session::logout(); return null; }
        if ($user) unset($user['auth_version']);
        return $user;
    }

    public function customer(bool $verified = false): array
    {
        $user = $this->current();
        if (!$user) { throw new ApiError(401, 'Please sign in.'); }
        if ($user['status'] !== 'active' || $user['role'] !== 'customer') {
            throw new ApiError(403, 'An active customer account is required.');
        }
        if ($verified && !$user['email_verified_at']) {
            throw new ApiError(403, 'Verify your email before checking out.');
        }
        return $user;
    }

    public function register(array $input): array
    {
        $name = Input::text($input, 'name', 100);
        $email = Input::email($input);
        $password = Input::password($input);
        $phone = RegistrationDetails::phone($input);
        $birthDate = RegistrationDetails::birthDate($input, $this->minimumAge);
        $address = AddressDetails::fromInput($input);
        $this->limitRegistration();
        // Never accept a client-supplied role, status, or verification flag.
        $userId = $this->db->transaction(function () use ($name, $email, $password, $phone, $birthDate, $address): int {
            if ($this->db->one('SELECT user_id FROM users WHERE email = ?', [$email])) {
                throw new ApiError(409, 'This email is already registered.');
            }
            $this->db->run('INSERT INTO users(name,email,password_hash,phone,birth_date) VALUES (?,?,?,?,?)', [$name, $email, password_hash($password, PASSWORD_DEFAULT), $phone, $birthDate]);
            $id = $this->db->id();
            $this->db->run('INSERT INTO carts(customer_id) VALUES (?)', [$id]);
            $this->db->run('INSERT INTO addresses(user_id,label,recipient_name,phone,address_text,house_number,street,province_code,city_code,barangay_code,postal_code,is_default) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$id, 'Home', $name, $phone, ...array_values($address), 1]);
            return $id;
        });
        Session::login($userId);
        try {
            $this->sendVerification($userId, $email);
            $delivery = 'queued';
        } catch (ApiError $error) {
            // Account is already durable; let the customer retry delivery without registering twice.
            $delivery = 'unavailable';
        }
        return ['user' => $this->current(), 'csrf_token' => $_SESSION['csrf'], 'verification_delivery' => $delivery];
    }

    public function login(array $input): array
    {
        $email = Input::email($input);
        $password = Input::password($input, 1);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $buckets = ['ip:' . hash('sha256', $ip), 'account:' . hash('sha256', $email)];
        $this->db->transaction(function () use ($buckets): void {
            $this->db->run('DELETE FROM login_attempts WHERE reset_at < ?', [time()]);
            foreach ($buckets as $bucket) {
                $row = $this->db->one('SELECT * FROM login_attempts WHERE bucket = ?', [$bucket]);
                $limit = str_starts_with($bucket, 'ip:') ? 30 : 10;
                if ($row && $row['attempts'] >= $limit) { throw new ApiError(429, 'Too many sign-in attempts. Try again in 15 minutes.'); }
                $this->db->run('INSERT INTO login_attempts(bucket,attempts,reset_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET attempts = attempts + 1', [$bucket, time() + 900]);
            }
        });
        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$user || !$valid) { throw new ApiError(401, 'Email or password is incorrect.'); }
        if ($user['status'] !== 'active' || $user['role'] !== 'customer') { throw new ApiError(403, 'An active customer account is required.'); }
        $this->db->run('DELETE FROM login_attempts WHERE bucket = ?', [$buckets[1]]);
        Session::login((int) $user['user_id'],(int)$user['auth_version']);
        return ['user' => $this->current(), 'csrf_token' => $_SESSION['csrf']];
    }

    public function changePassword(array $input): array
    {
        $user = $this->customer();
        $current = Input::password(['password'=>$input['current_password'] ?? null],1);
        $password = Input::password(['password'=>$input['new_password'] ?? null]);
        if (!isset($input['confirm_password']) || !is_string($input['confirm_password']) || $password!==$input['confirm_password']) { throw new ApiError(422,'New passwords do not match.'); }
        $version = $this->db->transaction(function () use ($user,$current,$password): int {
            $bucket = 'password:'.$user['user_id'];
            $this->db->run('DELETE FROM login_attempts WHERE reset_at<?',[time()]);
            if (($this->db->one('SELECT attempts FROM login_attempts WHERE bucket=?',[$bucket])['attempts'] ?? 0)>=10) { throw new ApiError(429,'Too many password attempts. Try again in 15 minutes.'); }
            $record = $this->db->one('SELECT password_hash,auth_version FROM users WHERE user_id=?',[$user['user_id']]);
            if (!password_verify($current,$record['password_hash'])) { return -1; }
            if (password_verify($password,$record['password_hash'])) { throw new ApiError(422,'Choose a different new password.'); }
            $this->db->run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE user_id=?',[password_hash($password,PASSWORD_DEFAULT),$user['user_id']]);
            $this->db->run('DELETE FROM login_attempts WHERE bucket=?',[$bucket]);
            (new NotificationService($this->db))->notify((int)$user['user_id'],'Password changed','Your password was changed. Other signed-in sessions have been signed out.','Account');
            return (int)$record['auth_version']+1;
        });
        if ($version<0) {
            $this->db->run('INSERT INTO login_attempts(bucket,attempts,reset_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET attempts=attempts+1',['password:'.$user['user_id'],time()+900]);
            throw new ApiError(422,'Current password is incorrect.');
        }
        Session::login((int)$user['user_id'],$version);
        return ['changed'=>true,'csrf_token'=>$_SESSION['csrf']];
    }

    private function sendVerification(int $id, string $email): void
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run('INSERT INTO email_verifications(user_id,token_hash,expires_at,sent_at) VALUES (?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET token_hash=excluded.token_hash,expires_at=excluded.expires_at,sent_at=excluded.sent_at', [$id, hash('sha256', $token), time() + 3600, time()]);
        $this->mailer->verification($email, $token);
    }

    private function limitRegistration(): void
    {
        $bucket = 'register:' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $this->db->transaction(function () use ($bucket): void {
            $this->db->run('DELETE FROM login_attempts WHERE reset_at < ?', [time()]);
            $record = $this->db->one('SELECT attempts FROM login_attempts WHERE bucket=?', [$bucket]);
            if ($record && $record['attempts'] >= 5) { throw new ApiError(429, 'Too many registrations. Try again in 15 minutes.'); }
            $this->db->run('INSERT INTO login_attempts(bucket,attempts,reset_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET attempts=attempts+1', [$bucket, time() + 900]);
        });
    }

    public function resend(): array
    {
        $user = $this->customer();
        if ($user['email_verified_at']) { return ['verified' => true]; }
        $row = $this->db->one('SELECT sent_at FROM email_verifications WHERE user_id = ?', [$user['user_id']]);
        if ($row && time() - $row['sent_at'] < 60) { throw new ApiError(429, 'Wait a minute before requesting another email.'); }
        $this->sendVerification((int) $user['user_id'], $user['email']);
        return ['verification_delivery' => 'queued'];
    }

    public function verify(array $input): array
    {
        $token = Input::text($input, 'token', 64, 64);
        $this->db->transaction(function () use ($token): void {
            $record = $this->db->one('SELECT * FROM email_verifications WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), time()]);
            if (!$record) { throw new ApiError(422, 'Verification link is invalid or expired.'); }
            $this->db->run("UPDATE users SET email_verified_at = strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE user_id = ?", [$record['user_id']]);
            $this->db->run('DELETE FROM email_verifications WHERE user_id = ?', [$record['user_id']]);
        });
        return ['verified' => true];
    }
}
