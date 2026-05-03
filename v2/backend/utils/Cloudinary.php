<?php
namespace App\Utils;

use Exception;

class Cloudinary {
    private $cloudName;
    private $apiKey;
    private $apiSecret;

    public function __construct() {
        $this->cloudName = getenv('CLOUDINARY_CLOUD_NAME');
        $this->apiKey = getenv('CLOUDINARY_API_KEY');
        $this->apiSecret = getenv('CLOUDINARY_API_SECRET');
    }

    /**
     * Upload a file to Cloudinary
     * @param string $fileLocalPath Path to the local file (e.g. $_FILES['file']['tmp_name'])
     * @param string $folder Folder in Cloudinary (e.g. 'connectxion/profiles')
     * @return string The secure URL of the uploaded file
     */
    public function upload($fileLocalPath, $folder = 'connectxion/general') {
        if (!$this->apiKey || !$this->apiSecret) {
            throw new Exception("Cloudinary credentials not set.");
        }

        $timestamp = time();
        $signature = $this->generateSignature($timestamp, $folder);

        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/auto/upload";

        $postData = [
            'file' => new \CURLFile($fileLocalPath),
            'timestamp' => $timestamp,
            'api_key' => $this->apiKey,
            'signature' => $signature,
            'folder' => $folder
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $result = json_decode($response, true);
        curl_close($ch);

        if (isset($result['error'])) {
            throw new Exception("Cloudinary Upload Error: " . $result['error']['message']);
        }

        return $result['secure_url'];
    }

    private function generateSignature($timestamp, $folder) {
        $params = "folder=$folder&timestamp=$timestamp" . $this->apiSecret;
        return sha1($params);
    }
}
