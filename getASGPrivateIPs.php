#!/usr/bin/env php
<?php
/**
 * Get Private IPs of EC2 instances in an Auto Scaling Group
 * Usage: php getASGPrivateIPs.php <ASG_NAME>
 */

if ($argc < 2) {
    echo "Usage: php getASGPrivateIPs.php <ASG_NAME>\n";
    echo "Example: php getASGPrivateIPs.php my-auto-scaling-group\n";
    exit(1);
}

$asg_name = $argv[1];
$profile = "mfa";

echo "Fetching instances from ASG: $asg_name\n";
echo "Using AWS profile: $profile\n\n";

// Step 1: Get instance IDs from the Auto Scaling Group
$cmd_asg = "aws autoscaling describe-auto-scaling-groups --auto-scaling-group-names " . escapeshellarg($asg_name) . " --profile $profile 2>&1";
echo "Running: $cmd_asg\n";
$output_asg = shell_exec($cmd_asg);

// Check for AWS errors
if (preg_match('/ExpiredTokenException|Unable to locate credentials|token included in the request is invalid/i', $output_asg)) {
    echo "ERROR: AWS authentication failed. Please check your credentials.\n";
    echo "Output: $output_asg\n";
    exit(1);
}

$asg_data = json_decode($output_asg, true);

if (!isset($asg_data['AutoScalingGroups']) || empty($asg_data['AutoScalingGroups'])) {
    echo "ERROR: Auto Scaling Group '$asg_name' not found or empty.\n";
    echo "Raw output: $output_asg\n";
    exit(1);
}

$instances = $asg_data['AutoScalingGroups'][0]['Instances'] ?? [];

if (empty($instances)) {
    echo "No instances found in ASG '$asg_name'.\n";
    exit(0);
}

// Extract instance IDs
$instance_ids = array_map(function($instance) {
    return $instance['InstanceId'];
}, $instances);

echo "Found " . count($instance_ids) . " instance(s) in ASG: " . implode(", ", $instance_ids) . "\n\n";

// Step 2: Get private IPs for these instances
$instance_ids_string = implode(" ", $instance_ids);
$cmd_ec2 = "aws ec2 describe-instances --instance-ids $instance_ids_string --profile $profile --query 'Reservations[*].Instances[*].[InstanceId,PrivateIpAddress,State.Name,Tags[?Key==`Name`].Value|[0]]' --output json 2>&1";
echo "Running: $cmd_ec2\n";
$output_ec2 = shell_exec($cmd_ec2);

// Check for AWS errors
if (preg_match('/ExpiredTokenException|Unable to locate credentials|token included in the request is invalid/i', $output_ec2)) {
    echo "ERROR: AWS authentication failed while fetching instance details.\n";
    echo "Output: $output_ec2\n";
    exit(1);
}

$ec2_data = json_decode($output_ec2, true);

if (!is_array($ec2_data)) {
    echo "ERROR: Failed to parse EC2 instance data.\n";
    echo "Raw output: $output_ec2\n";
    exit(1);
}

// Display results
echo "\n=== Private IPs for ASG: $asg_name ===\n\n";
$private_ips = [];

foreach ($ec2_data as $reservation) {
    foreach ($reservation as $instance) {
        $instance_id = $instance[0];
        $private_ip = $instance[1];
        $state = $instance[2];
        $name = $instance[3] ?? 'N/A';
        
        echo "Instance ID: $instance_id\n";
        echo "  Name: $name\n";
        echo "  Private IP: $private_ip\n";
        echo "  State: $state\n";
        echo "\n";
        
        if ($state === 'running') {
            $private_ips[] = $private_ip;
        }
    }
}

// Output just the IPs (useful for piping)
echo "\n=== Running Instances Private IPs Only ===\n";
foreach ($private_ips as $ip) {
    echo "$ip\n";
}

echo "\nTotal running instances: " . count($private_ips) . "\n";

// Return comma-separated list for easy copy-paste
if (!empty($private_ips)) {
    echo "\nComma-separated: " . implode(",", $private_ips) . "\n";
}

?>
