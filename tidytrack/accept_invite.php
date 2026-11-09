<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/dp.php';

function esc($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ================================
   VALIDATE TOKEN
================================ */

$token = trim($_GET['token'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
  exit("Invalid invite token.");
}

/* ================================
   FETCH INVITE
================================ */

$stmt = $conn->prepare("
SELECT invite_id,email,role,status,expires_at
FROM user_invites
WHERE token=?
LIMIT 1
");

$stmt->bind_param("s",$token);
$stmt->execute();
$invite = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$invite){
  exit("Invite not found.");
}

if(strtolower($invite['status']) !== 'pending'){
  exit("This invite is not active.");
}

/* ================================
   CHECK EXPIRY
================================ */

if(!empty($invite['expires_at'])){
  $exp = strtotime($invite['expires_at']);
  if($exp && time() > $exp){

    $upd = $conn->prepare("UPDATE user_invites SET status='expired' WHERE invite_id=?");
    $id = (int)$invite['invite_id'];
    $upd->bind_param("i",$id);
    $upd->execute();
    $upd->close();

    exit("Invite link expired.");
  }
}

/* ================================
   INVITE DATA
================================ */

$email = strtolower(trim($invite['email']));
$role  = strtolower(trim($invite['role']));
$inviteId = (int)$invite['invite_id'];

$err='';
$ok='';

/* ================================
   FORM SUBMIT
================================ */

if($_SERVER['REQUEST_METHOD']==='POST'){

$name = trim($_POST['name'] ?? '');
$password = $_POST['password'] ?? '';
$password2 = $_POST['password2'] ?? '';

if($name==''){
  $err="Name is required.";
}

elseif(strlen($password)<6){
  $err="Password must be at least 6 characters.";
}

elseif($password !== $password2){
  $err="Passwords do not match.";
}

else{

  /* check existing email */

  $check = $conn->prepare("SELECT user_id FROM users WHERE email=? LIMIT 1");
  $check->bind_param("s",$email);
  $check->execute();
  $exists = $check->get_result()->num_rows;
  $check->close();

  if($exists){
    $err="This email already has an account.";
  }

  else{

    $hash = password_hash($password,PASSWORD_DEFAULT);

    $status='offline';
    $attendance='No';
    $google_user=0;
    $email_confirmed=0;
    $confirmation_token = bin2hex(random_bytes(32));

    $stmt=$conn->prepare("
    INSERT INTO users
    (name,email,password,role,status,attendance,google_user,email_confirmed,confirmation_token)
    VALUES (?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
      "ssssssiis",
      $name,
      $email,
      $hash,
      $role,
      $status,
      $attendance,
      $google_user,
      $email_confirmed,
      $confirmation_token
    );

    if($stmt->execute()){

      /* mark invite accepted */

      $update=$conn->prepare("UPDATE user_invites SET status='accepted' WHERE invite_id=?");
      $update->bind_param("i",$inviteId);
      $update->execute();
      $update->close();

      $ok="Account created successfully. You can now login.";

    }else{

      $err="Database error: ".$stmt->error;

    }

    $stmt->close();
  }

}

}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Accept Invite</title>

<style>
body{
  margin:0;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  font-family:Poppins,Arial,sans-serif;
  background:linear-gradient(135deg,#f3e8ff 0%, #ede9fe 45%, #f5f3ff 100%);
  color:#1f2937;
}

.wrapper{
  width:100%;
  max-width:520px;
  padding:24px;
}

.card{
  background:rgba(255,255,255,0.96);
  border:1px solid #e9d5ff;
  border-radius:20px;
  padding:28px;
  box-shadow:0 18px 45px rgba(109,40,217,0.12);
  backdrop-filter:blur(4px);
}

h2{
  margin:0 0 10px;
  color:#6d28d9;
  text-align:center;
  font-size:1.7rem;
}

p{
  margin:0 0 14px;
  text-align:center;
  color:#6b7280;
}

.meta{
  font-size:14px;
  margin:0 0 18px;
  color:#5b5870;
  background:#faf5ff;
  border:1px solid #e9d5ff;
  padding:12px 14px;
  border-radius:12px;
  line-height:1.7;
  text-align:center;
}

input,select{
  width:100%;
  padding:12px 14px;
  margin-bottom:14px;
  border:1px solid #ddd6fe;
  border-radius:12px;
  font-size:14px;
  box-sizing:border-box;
  background:#fff;
  color:#1f2937;
  transition:all .2s ease;
}

input:focus,select:focus{
  outline:none;
  border-color:#8b5cf6;
  box-shadow:0 0 0 4px rgba(139,92,246,0.12);
}

input[readonly]{
  background:#f5f3ff;
  color:#6b7280;
  cursor:not-allowed;
}

select:disabled{
  background:#f5f3ff;
  color:#6b7280;
  cursor:not-allowed;
}

button{
  width:100%;
  padding:13px;
  border:none;
  border-radius:12px;
  background:linear-gradient(135deg,#8b5cf6 0%, #6d28d9 100%);
  color:white;
  font-weight:700;
  font-size:15px;
  cursor:pointer;
  transition:transform .15s ease, box-shadow .15s ease;
  box-shadow:0 10px 20px rgba(109,40,217,0.18);
}

button:hover{
  transform:translateY(-1px);
  box-shadow:0 14px 24px rgba(109,40,217,0.22);
}

.error{
  background:#fef2f2;
  border:1px solid #fecaca;
  padding:12px;
  border-radius:10px;
  margin-bottom:14px;
  color:#991b1b;
  font-size:14px;
}

.success{
  background:#ecfdf5;
  border:1px solid #bbf7d0;
  padding:12px;
  border-radius:10px;
  margin-bottom:14px;
  color:#166534;
  font-size:14px;
  text-align:center;
}

a{
  color:#6d28d9;
  text-decoration:none;
  font-weight:700;
}

a:hover{
  text-decoration:underline;
}
</style>

</head>

<body>

<div class="wrapper">

<div class="card">

<h2>Accept Invite</h2>

<p>Create your account to join the system.</p>

<div class="meta">
<b>Email:</b> <?=esc($email)?> |
<b>Role:</b> <?=esc($role)?>
</div>

<?php if($err): ?>
<div class="error"><?=esc($err)?></div>
<?php endif; ?>

<?php if($ok): ?>

<div class="success"><?=esc($ok)?></div>

<p style="margin-top:10px">
<a href="https://investhoodprojects.com/tidytracker/tidytrack/">Go to login</a>
</p>

<?php else: ?>

<form method="POST">

<input type="text"
name="name"
placeholder="👤 Your Name"
required>

<input type="email"
name="email"
value="<?=esc($email)?>"
readonly>

<input type="password"
name="password"
placeholder="🔒 Password"
required>

<input type="password"
name="password2"
placeholder="🔒 Confirm Password"
required>

<select disabled>
<option value="member" <?=$role=='member'?'selected':''?>>👥 Member</option>
<option value="leader" <?=$role=='leader'?'selected':''?>>📈 Leader</option>
<option value="manager" <?=$role=='manager'?'selected':''?>>💼 Manager</option>
<option value="admin" <?=$role=='admin'?'selected':''?>>🔐 Admin</option>
</select>

<button type="submit">
Accept Invite & Create Account
</button>

</form>

<?php endif; ?>

</div>
</div>

</body>
</html>