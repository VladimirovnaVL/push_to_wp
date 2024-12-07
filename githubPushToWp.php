<?php

class ConfluenceToWordPress {
    private $confluenceApiToken = '111';
    private $wpAuthToken = '222=';
    private $wpBaseUrl = 'https://portal.333com/wp-json/wp/v2';
    private $cfPropUrlTemplate = 'https://444.com/tap-confluence/rest/api/content/%s/property';
    private $fetchContentUrlTemplate = 'https://444.com/tap-confluence/rest/api/content/%s?expand=body.view,metadata.properties,version';
    private $fetchMetadataUrlTemplate = 'https://444.com/tap-confluence/rest/mobile/1.0/content/%s?knownContexts=&knownResources=';

    public function postToWordPress($pageId) {
        try {
            // Fetch Confluence page content
            $contentData = $this->fetchConfluencePageContent($pageId);

            $pageContent = $this->extractPanelContent($contentData['body']['view']['value']);
            $metadataProperties = $this->fetchMetadataProperties($pageId);
            $wpPageId = $metadataProperties['wordpress']['wpPageId'] ?? null;

            // Process images and fetch updated metadata
            $wpImageData = $this->processImages($pageContent, $metadataProperties['wordpress']['wpImageId'] ?? []);
            $cleanedContent = $this->cleanContent($pageContent, $wpImageData);

            // Create or update the WordPress page
            if ($wpPageId) {
                $this->updateWordPressPage($wpPageId, $cleanedContent);
            } else {
                $wpPageId = $this->createWordPressPage($cleanedContent, $contentData['title']);
            }

            // Update Confluence metadata
            $this->updateConfluenceMetadata($pageId, $wpPageId, $wpImageData);

            return "Page successfully posted to WordPress.";
        } catch (Exception $myerror) {
            return "Error: " . $myerror->getMessage();
        }
    }

    private function fetchConfluencePageContent($pageId) {
        $url = sprintf($this->fetchContentUrlTemplate, $pageId);
        return $this->makeApiRequest($url, 'GET');
    }

    private function fetchMetadataProperties($pageId) {
        $url = sprintf($this->cfPropUrlTemplate, $pageId);
        return $this->makeApiRequest($url, 'GET');
    }

    private function processImages($htmlContent, $existingWpImageIds) {
        $doc = new DOMDocument();
        @$doc->loadHTML($htmlContent);
        $images = $doc->getElementsByTagName('img');
        $wpImageIds = $existingWpImageIds;

        foreach ($images as $img) {
            $imageName = $img->getAttribute('data-linked-resource-default-alias');
            if (isset($existingWpImageIds[$imageName])) {
                continue; // Skip if already pushed
            }

            $imageUrl = $img->getAttribute('src');
            $uploadResponse = $this->uploadImageToWordPress($imageUrl, $imageName);
            $wpImageIds[$imageName] = [
                'id' => $uploadResponse['id'],
                'url' => $uploadResponse['source_url']
            ];
        }

        return ['wpImageIds' => $wpImageIds];
    }
// start from here
    
    private function uploadImageToWordPress($imageUrl, $imageName) {
        $imageContent = file_get_contents($imageUrl);
        $boundary = md5(time());
        $body = "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"$imageName\"\r\n";
        $body .= "Content-Type: image/jpeg\r\n\r\n";
        $body .= $imageContent . "\r\n";
        $body .= "--$boundary--";

        $headers = [
            "Authorization: Basic " . $this->wpAuthToken,
            "Content-Type: multipart/form-data; boundary=$boundary",
            "Content-Length: " . strlen($body)
        ];

        return $this->makeApiRequest($this->wpBaseUrl . '/media', 'POST', $body, $headers);
    }

    private function cleanContent($content, $wpImageData) {
        foreach ($wpImageData['wpImageIds'] as $alias => $image) {
            $content = str_replace("data-linked-resource-default-alias=\"$alias\"", "src=\"{$image['url']}\"", $content);
        }
        return $content;
    }

    private function updateConfluenceMetadata($pageId, $wpPageId, $wpImageData) {
        $url = sprintf($this->cfPropUrlTemplate, $pageId);
        $body = [
            'key' => 'wordpress',
            'value' => [
                'wpPageId' => $wpPageId,
                'wpImageId' => $wpImageData['wpImageIds']
            ]
        ];
        $this->makeApiRequest($url, 'POST', json_encode($body));
    }

    private function makeApiRequest($url, $method, $body = null, $headers = []) {
        $curl = curl_init();
        $defaultHeaders = [
            "Authorization: Bearer " . $this->confluenceApiToken,
            "Content-Type: application/json"
        ];

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers)
        ]);

        if ($body) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            throw new Exception("API Request failed: " . curl_error($curl));
        }

        return json_decode($response, true);
    }
}

$pageId = $_GET['pageId'] ?? null;
if ($pageId) {
    $integration = new ConfluenceToWordPress();
    echo $integration->postToWordPress($pageId);
} else {
    echo "Error: Page ID is required.";
}
