<?php
if (isset($_POST['Name'], $_POST['Email'], $_POST['Password']) && !empty($_POST['Name'])) {
    // Assign POST data to variables
    $Name = $_POST['Name'];
    $Email = $_POST['Email'];
    $Password = $_POST['Password'];

    // Output the received data for debugging
    echo "Name: $Name<br>";
    echo "Email: $Email<br>";
    echo "Password: $Password<br>";

    // Database connection
    $conn = new mysqli('localhost', 'root', '', 'ged');
    if ($conn->connect_error) {
        die('Connection failed: ' . $conn->connect_error);
    } else {
        $stmt = $conn->prepare("INSERT INTO sign_up (Name, Email, Password) VALUES (?, ?, ?)");
        if (!$stmt) {
            echo "Error: " . $conn->error;
            die();
        }

        // Bind parameters and execute statement
        $stmt->bind_param("sss", $Name, $Email, $Password);
        $execval = $stmt->execute();
        if (!$execval) {
            echo "Error: " . $stmt->error;
            die();
        }

        // Close statement and connection
        $stmt->close();
        $conn->close();

        // Redirect to home.html after successful registration
        header("Location: index.html");
        exit(); // Ensure script execution stops after redirection
    }
} else {
    // Handle the case where required POST variables are missing or empty
    echo "Error: Required POST variables 'Name', 'Email', and 'Password' are missing or empty.";
}
?>
