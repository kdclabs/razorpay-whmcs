<?php

# Required File Includes
include("../../../dbconnect.php");
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");

$gatewaymodule = "razorpay";

$GATEWAY = getGatewayVariables($gatewaymodule);

# Checks gateway module is active before accepting callback
if (!$GATEWAY["type"])
    die("Module Not Activated");

$key_id = $GATEWAY["KeyId"];
$key_secret = $GATEWAY["KeySecret"];


# Get Returned Variables
$merchant_order_id = $_POST["merchant_order_id"];
$razorpay_payment_id = $_POST["razorpay_payment_id"];

# Checks invoice ID is a valid invoice number or ends processing
$merchant_order_id = checkCbInvoiceID($merchant_order_id, $GATEWAY["name"]); 

# Checks transaction number isn't already in the database and ends processing if it does
checkCbTransID($razorpay_payment_id); 

# Fetch invoice to get the amount
$result = mysql_fetch_assoc(select_query('tblinvoices','total',array("id"=>$merchant_order_id))); 
$amount = $result['total'];

# Check if amount is INR, convert if not.
$currency = getCurrency();
if($currency['code'] !== 'INR') {
    $result = mysql_fetch_array(select_query( "tblcurrencies", "id", array( "code" => 'INR' )));
    $inr_id= $result['id'];
    $converted_amount = convertCurrency($amount,$currency['id'], $inr_id);
}
else {
    $converted_amount = $amount;
}

# Amount in Paisa
$converted_amount = 100*$converted_amount;

$success = true;
$error = "";

try {
    $url = 'https://api.razorpay.com/v1/payments/'.$razorpay_payment_id.'/capture';
    $fields_string="amount=$converted_amount";

    //cURL Request
    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt($ch,CURLOPT_URL, $url);
    curl_setopt($ch,CURLOPT_USERPWD, $key_id . ":" . $key_secret);
    curl_setopt($ch,CURLOPT_TIMEOUT, 60);
    curl_setopt($ch,CURLOPT_POST, 1);
    curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER, TRUE);

    //execute post
    $result = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);


    if($result === false) {
        $success = false;
        $error = 'Curl error: ' . curl_error($ch);
    }
    else {
        $response_array = json_decode($result, true);
        //Check success response
        if($http_status === 200 and isset($response_array['error']) === false){
            $success = true;    
        }
        else {
            $success = false;

            if(!empty($response_array['error']['code'])) {
                $error = $response_array['error']['code'].":".$response_array['error']['description'];
            }
            else {
                $error = "RAZORPAY_ERROR:Invalid Response <br/>".$result;
            }
        }
    }
        
    //close connection
    curl_close($ch);
}
catch (Exception $e) {
    $success = false;
    $error ="WHMCS_ERROR:Request to Razorpay Failed";
}

if ($success === true) {
    # Successful 
    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($merchant_order_id, $razorpay_payment_id, $amount, 0, $GATEWAY["name"]);
    logTransaction($GATEWAY["name"], $_POST, "Successful"); # Save to Gateway Log: name, data array, status
} 
else {
    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($GATEWAY["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$_POST['razorpay_payment_id']);
}

header( "Location: ".$GATEWAY['systemurl']."/viewinvoice.php?id=" . $merchant_order_id );
?>
