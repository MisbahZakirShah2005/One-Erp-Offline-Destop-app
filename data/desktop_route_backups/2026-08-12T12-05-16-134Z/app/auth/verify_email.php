<?php
include('../../config/db.php');

if(isset($_GET['token']) && !empty($_GET['token'])){

    $token = $_GET['token'];

    // Check if token exists and email not verified
    $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE verification_token=? AND email_verified=0");
    $stmt->bind_param("s",$token);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows > 0){
        $user = $result->fetch_assoc();

        // Update user as verified
        $update = $conn->prepare("UPDATE users SET email_verified=1, verification_token=NULL WHERE verification_token=?");
        $update->bind_param("s",$token);
        $update->execute();

        // Optional: send confirmation email
        $to = $user['email'];
        $subject = "Email Verified Successfully - SaaS POS";
        $message = "
            <h2>Hello ".$user['name'].",</h2>
            <p>Your email has been successfully verified!</p>
            <p>You can now <a href='https://saaspos.gapsoedigital.com/public/app/views/login_view.php'>login</a> to your account.</p>
            <br>
            <p>Regards,<br>SaaS POS Team</p>
        ";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: info@gapsoedigital.com\r\n";

        mail($to, $subject, $message, $headers);

        echo "<h2 style='color:green;'>Email verified successfully! ✅</h2>";
        echo "<p><a href='https://saaspos.gapsoedigital.com/public/app/views/login_view.php'>Click here to login</a></p>";

    } else {
        echo "<h2 style='color:red;'>Invalid or expired token. ❌</h2>";
    }

} else {
    echo "<h2 style='color:red;'>No token provided. ❌</h2>";
}
?>