<?php
// admin_components/spy_token.php
require 'includes/db_connection.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Token Spy</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-2xl mx-auto bg-white p-6 rounded shadow">
        <h2 class="text-xl font-bold mb-4">Database Token Spy</h2>
        
        <form method="POST" class="mb-6 flex gap-2">
            <input type="email" name="email" placeholder="Enter the email you are testing" required 
                   class="flex-1 p-2 border rounded">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Check DB</button>
        </form>

        <?php
        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $email = $_POST['email'];
            
            // Get RAW data from DB
            $stmt = $conn->prepare("SELECT user_id, username, email, reset_token, reset_expires, NOW() as server_time FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();

            if ($data) {
                echo "<div class='space-y-2 font-mono text-sm'>";
                echo "<p><strong>User ID:</strong> " . $data['user_id'] . "</p>";
                echo "<p><strong>Username:</strong> " . $data['username'] . "</p>";
                
                // SHOW THE TOKEN
                echo "<div class='p-4 bg-gray-50 border border-gray-200 rounded mt-2'>";
                if (empty($data['reset_token'])) {
                    echo "<span class='text-red-500 font-bold'>TOKEN IS NULL / EMPTY!</span>";
                    echo "<p class='text-gray-500'>The 'Forgot Password' page did NOT save the token.</p>";
                } else {
                    echo "<p><strong>Token in DB:</strong> <span class='bg-yellow-200 px-1'>" . $data['reset_token'] . "</span></p>";
                    echo "<p><strong>Token Length:</strong> " . strlen($data['reset_token']) . " chars</p>";
                    
                    // Generate a DIRECT link
                    $directLink = "reset_password.php?token=" . $data['reset_token'];
                    echo "<p class='mt-4'><strong>Test Link:</strong> <a href='$directLink' class='text-blue-600 underline'>$directLink</a></p>";
                }
                echo "</div>";

                // SHOW TIME
                echo "<div class='mt-2 text-xs text-gray-500'>";
                echo "Expires: " . $data['reset_expires'] . "<br>";
                echo "Server Time: " . $data['server_time'];
                echo "</div>";
                echo "</div>";
            } else {
                echo "<p class='text-red-600 font-bold'>No user found with that email!</p>";
            }
        }
        ?>
    </div>
</body>
</html>