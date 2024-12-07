
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

            // Fetch additional metadata
            $additionalMetadata = $this->fetchAdditionalMetadata($pageId);
            $title = $additionalMetadata['title'] ?? 'No Title';
            $authorName = $additionalMetadata['author']['fullName'] ?? 'Unknown Author';
            $authorEmail = $additionalMetadata['author']['email'] ?? 'no-reply@example.com';

            // Ensure author exists in WordPress
            $authorId = $this->ensureAuthorExists($authorName, $authorEmail);

            // Fetch metadata properties
            $metadataProperties = $this->fetchMetadataProperties($pageId);
            $wpPageId = $metadataProperties['wordpress']['wpPageId'] ?? null;

            // Process images and clean content
            $wpImageData = $this->processImages($pageContent, $metadataProperties['wordpress']['wpImageId'] ?? []);
            $cleanedContent = $this->cleanContent($pageContent, $wpImageData);

            // Create or update the WordPress page
            if ($wpPageId) {
                $this->updateWordPressPage($wpPageId, $cleanedContent);
            } else {
                $wpPageId = $this->createWordPressPage($cleanedContent, $title, $authorId);
            }

            // Update Confluence metadata
            $this->updateConfluenceMetadata($pageId, $wpPageId, $wpImageData);

            return "Page successfully posted to WordPress.";
        } catch (Exception $e) {
            error_log("Error posting to WordPress: " . $e->getMessage());
            return "Error: " . $e->getMessage();
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

    private function fetchAdditionalMetadata($pageId) {
        $url = sprintf($this->fetchMetadataUrlTemplate, $pageId);
        return $this->makeApiRequest($url, 'GET');
    }

    private function extractPanelContent($content) {
        preg_match('/<div class="panelContent">(.*?)<\/div>/s', $content, $matches);
        return $matches[1] ?? $content;
    }

    private function processImages($html, $existingImages) {
        // Logic to process and upload images (similar to JavaScript functionality)
        // Returns an updated array of image metadata for WordPress.
        return $existingImages;
    }

    private function cleanContent($content, $wpImageData) {
        $content = preg_replace('/<script.*?<\/script>/s', '', $content);
        $content = preg_replace('/<style.*?<\/style>/s', '', $content);
        $content = preg_replace('/<div class="conf-macro.*?<\/div>/s', '', $content);
        $content = preg_replace('/<table(.*?)>/s', '<div class="table"><table>', $content);
        $content = preg_replace('/<\/table>/s', '</table></div>', $content);
        // Handle image replacement
        foreach ($wpImageData as $alias => $data) {
            $content = str_replace(
                "data-linked-resource-default-alias=\"$alias\"",
                "src=\"{$data['url']}\"",
                $content
            );
        }
        return $content;
    }

    private function createWordPressPage($content, $title, $authorId) {
        $url = $this->wpBaseUrl . '/pages';
        $data = [
            'title' => $title,
            'content' => $content,
            'status' => 'publish',
            'author' => $authorId
        ];
        return $this->makeApiRequest($url, 'POST', $data)['id'];
    }

    private function updateWordPressPage($pageId, $content) {
        $url = $this->wpBaseUrl . "/pages/$pageId";
        $data = ['content' => $content, 'status' => 'publish'];
        $this->makeApiRequest($url, 'PUT', $data);
    }

    private function ensureAuthorExists($name, $email) {
        $url = $this->wpBaseUrl . "/users?search=$email";
        $response = $this->makeApiRequest($url, 'GET');
        if (!empty($response)) {
            return $response[0]['id']; // Author exists
        }
        // Create new author
        $url = $this->wpBaseUrl . '/users';
        $data = [
            'username' => $name,
            'email' => $email,
            'password' => bin2hex(random_bytes(8))
        ];
        return $this->makeApiRequest($url, 'POST', $data)['id'];
    }

    private function updateConfluenceMetadata($pageId, $wpPageId, $wpImageData) {
        $url = sprintf($this->cfPropUrlTemplate, $pageId);
        $data = [
            'key' => 'wordpress',
            'value' => [
                'wpPageId' => $wpPageId,
                'wpImageId' => $wpImageData
            ]
        ];
        $this->makeApiRequest($url, 'PUT', $data);
    }

    private function makeApiRequest($url, $method, $data = null) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $this->wpAuthToken,
            'Content-Type: application/json'
        ]);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
    }
}
?>
