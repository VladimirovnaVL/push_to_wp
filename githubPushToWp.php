<?php

class ConfluenceToWordPress {
    private $confluenceApiToken = '111'; // Ensure this token is used in all Confluence API calls
    private $wpAuthToken = '222='; // Ensure this token is used in all WordPress API calls
    private $wpBaseUrl = 'https://portal.333com/wp-json/wp/v2';
    private $cfPropUrlTemplate = 'https://444.com/tap-confluence/rest/api/content/%s/property';
    private $fetchContentUrlTemplate = 'https://444.com/tap-confluence/rest/api/content/%s?expand=body.view,metadata.properties,version';
    private $fetchMetadataUrlTemplate = 'https://444.com/tap-confluence/rest/mobile/1.0/content/%s?knownContexts=&knownResources=';

    public function postToWordPress($pageId) {
        try {
            // Step 1: Fetch Confluence page content
            $contentData = $this->fetchConfluencePageContent($pageId);
            $pageContent = $this->extractPanelContent($contentData['body']['view']['value']);

            // Step 2: Fetch additional metadata (title, author details)
            $additionalMetadata = $this->fetchAdditionalMetadata($pageId);
            $title = $additionalMetadata['title'] ?? 'No Title';
            $authorName = $additionalMetadata['author']['fullName'] ?? 'Unknown Author';
            $authorEmail = $additionalMetadata['author']['email'] ?? 'no-reply@example.com';

            // Step 3: Ensure author exists in WordPress
            $authorId = $this->ensureAuthorExists($authorName, $authorEmail);

            // Step 4: Fetch metadata properties for WordPress integration
            $metadataProperties = $this->fetchMetadataProperties($pageId);
            $wpPageId = $metadataProperties['wordpress']['wpPageId'] ?? null;

            // Step 5: Process images and clean content
            $wpImageData = $this->processImages($pageContent, $metadataProperties['wordpress']['wpImageId'] ?? []);
            $cleanedContent = $this->cleanContent($pageContent, $wpImageData);

            // Step 6: Create or update WordPress page
            if ($wpPageId) {
                $this->updateWordPressPage($wpPageId, $cleanedContent);
            } else {
                $wpPageId = $this->createWordPressPage($cleanedContent, $title, $authorId);
            }

            // Step 7: Update Confluence metadata with WordPress details
            $this->updateConfluenceMetadata($pageId, $wpPageId, $wpImageData);

            return "Page successfully posted to WordPress.";
        } catch (Exception $e) {
            error_log("Error posting to WordPress: " . $e->getMessage());
            return "Error: " . $e->getMessage();
        }
    }

    private function fetchConfluencePageContent($pageId) {
        $url = sprintf($this->fetchContentUrlTemplate, $pageId);
        return $this->makeApiRequest($url, 'GET', [], true);
    }

    private function fetchMetadataProperties($pageId) {
        $url = sprintf($this->cfPropUrlTemplate, $pageId);
        return $this->makeApiRequest($url, 'GET', [], true);
    }

    private function fetchAdditionalMetadata($pageId) {
        $url = sprintf($this->fetchMetadataUrlTemplate, $pageId);
        return $this->makeApiRequest($url, 'GET', [], true);
    }

    private function extractPanelContent($content) {
        preg_match('/<div class="panelContent">(.*?)<\/div>/s', $content, $matches);
        return $matches[1] ?? $content;
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

    private function cleanContent($content, $wpImageData) {
        $content = preg_replace('/<script.*?<\/script>/s', '', $content);
        $content = preg_replace('/<style.*?<\/style>/s', '', $content);
        $content = preg_replace('/<div class="conf-macro.*?<\/div>/s', '', $content);
        $content = preg_replace('/<table(.*?)>/s', '<div class="table"><table>', $content);
        $content = str_replace('/<\/table>/s', '</table></div>', $content);
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
        return $this->makeApiRequest($url, 'POST', $data, false)['id'];
    }

    private function updateWordPressPage($pageId, $content) {
        $url = $this->wpBaseUrl . "/pages/$pageId";
        $data = ['content' => $content, 'status' => 'publish'];
        $this->makeApiRequest($url, 'PUT', $data, false);
    }

    private function ensureAuthorExists($name, $email) {
        $url = $this->wpBaseUrl . "/users?search=$email";
        $response = $this->makeApiRequest($url, 'GET', [], false);
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
        return $this->makeApiRequest($url, 'POST', $data, false)['id'];
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
        $this->makeApiRequest($url, 'PUT', $data, true);
    }

    private function makeApiRequest($url, $method, $data = null, $isConfluence = false) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = [
            'Content-Type: application/json'
        ];
        if ($isConfluence) {
            $headers[] = 'Authorization: Bearer ' . $this->confluenceApiToken;
        } else {
            $headers[] = 'Authorization: Basic ' . $this->wpAuthToken;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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

