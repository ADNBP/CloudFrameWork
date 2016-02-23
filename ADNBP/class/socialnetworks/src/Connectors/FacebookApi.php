<?php
namespace CloudFramework\Service\SocialNetworks\Connectors;

use CloudFramework\Patterns\Singleton;
use CloudFramework\Service\SocialNetworks\Exceptions\ConnectorConfigException;
use CloudFramework\Service\SocialNetworks\Exceptions\ConnectorServiceException;
use CloudFramework\Service\SocialNetworks\Exceptions\MalformedUrlException;
use CloudFramework\Service\SocialNetworks\Interfaces\SocialNetworkInterface;
use Facebook\Facebook;

class FacebookApi extends Singleton implements SocialNetworkInterface
{
    const ID = 'facebook';
    const FACEBOOK_SELF_USER = "me";
    const MAX_IMPORT_FILE_SIZE = 37748736; // 36MB
    const MAX_IMPORT_FILE_SIZE_MB = 36;

    // Google client object
    private $client;

    // API keys
    private $clientId;
    private $clientSecret;
    private $clientScope = array();

    // Auth keys
    private $accessToken;

    /**
     * Set Facebook Api keys
     * @param $clientId
     * @param $clientSecret
     * @param $clientScope
     * @throws ConnectorConfigException
     */
    public function setApiKeys($clientId, $clientSecret, $clientScope) {
        if ((null === $clientId) || ("" === $clientId)) {
            throw new ConnectorConfigException("'clientId' parameter is required", 601);
        }

        if ((null === $clientSecret) || ("" === $clientSecret)) {
            throw new ConnectorConfigException("'clientSecret' parameter is required", 602);
        }

        if ((null === $clientScope) || (!is_array($clientScope)) || (count($clientScope) == 0)) {
            throw new ConnectorConfigException("'clientScope' parameter is required", 603);
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->clientScope = $clientScope;

        $this->client = new Facebook(array(
            "app_id" => $this->clientId,
            "app_secret" => $this->clientSecret,
            'default_graph_version' => 'v2.4',
            'cookie' => true
        ));
    }

    /**
     * Service that request authorization to Facebook making up the Facebook login URL
     * @param string $redirectUrl
     * @return array
     * @throws ConnectorConfigException
     * @throws MalformedUrlException
     */
    public function requestAuthorization($redirectUrl)
    {
        if ((null === $redirectUrl) || (empty($redirectUrl))) {
            throw new ConnectorConfigException("'redirectUrl' parameter is required", 628);
        } else {
            if (!$this->wellFormedUrl($redirectUrl)) {
                throw new MalformedUrlException("'redirectUrl' is malformed", 601);
            }
        }

        $redirect = $this->client->getRedirectLoginHelper();

        $authUrl = $redirect->getLoginUrl($redirectUrl, $this->clientScope);

        // Authentication request
        return $authUrl;
    }

    /**
     * Authentication service from Facebook sign in request
     * @param null $code
     * @param $redirectUrl
     * @return array
     * @throws ConnectorServiceException
     */
    public function authorize($code = null, $redirectUrl)
    {
        try {
            $helper = $this->client->getRedirectLoginHelper();
            $accessToken = $helper->getAccessToken();

            if (empty($accessToken)) {
                throw new ConnectorServiceException("Error taking access token from Facebook Api", 500);
            }
        } catch(\Exception $e) {
            throw new ConnectorServiceException($e->getMessage(), $e->getCode());
        }

        return array("access_token" => $accessToken->getValue());
    }

    /**
     * Method that inject the access token in connector
     * @param array $credentials
     */
    public function setAccessToken(array $credentials) {
        $this->accessToken = $credentials["access_token"];
    }

    /**
     * Service that check if credentials are valid
     * @param $credentials
     * @return null
     * @throws ConnectorConfigException
     */
    public function checkCredentials($credentials) {
        $this->checkCredentialsParameters($credentials);

        try {
            return $this->getProfile(self::FACEBOOK_SELF_USER);
        } catch(\Exception $e) {
            throw new ConnectorConfigException("Invalid credentials set'");
        }
    }

    public function revokeToken() {
        return;
    }

    /**
     * Service that query to Facebook Api a followers count
     * @return int
     */
    public function getFollowers($userId = null, $maxResultsPerPage, $numberOfPages, $pageToken)
    {
        $response = $this->client->get("/".self::FACEBOOK_SELF_USER."/friends", $this->accessToken)->getDecodedBody();
        return $response["summary"]["total_count"];
    }

    public function getFollowersInfo($userId, $postId) {
        return;
    }

    public function getSubscribers($userId, $maxResultsPerPage, $numberOfPages, $nextPageUrl) {
        return;
    }

    public function getPosts($userId, $maxResultsPerPage, $numberOfPages, $pageToken) {
        return;
    }

    /**
     * Service that query to Facebook Api to get user profile
     * @param $userId
     * @return string
     * @throws ConnectorServiceException
     */
    public function getProfile($userId) {
        $this->checkUser($userId);

        try {
            $response = $this->client->get("/".$userId."?fields=id,name,first_name,middle_name,last_name,email", $this->accessToken);
        } catch(\Exception $e) {
            throw new ConnectorServiceException('Error getting user profile: ' . $e->getMessage(), $e->getCode());
        }

        $profile = array(
            "user_id" => $response->getGraphUser()->getId(),
            "name" => $response->getGraphUser()->getName(),
            "first_name" => $response->getGraphUser()->getFirstName(),
            "middle_name" => $response->getGraphUser()->getMiddleName(),
            "last_name" => $response->getGraphUser()->getLastName(),
            "email" => $response->getGraphUser()->getEmail()
        );

        return json_encode($profile);
    }

    /**
     * Service that upload a media file (image) to Facebook
     * @param string $userId
     * @param string $mediaType "url"|"path"
     * @param string $value url or path
     * @param string $title message for the media
     * @return JSON
     * @throws AuthenticationException
     * @throws ConnectorConfigException
     * @throws ConnectorServiceException
     */
    public function importMedia($userId, $mediaType, $value, $title, $albumId)
    {
        $this->checkUser($userId);

        if ((null === $mediaType) || ("" === $mediaType)) {
            throw new ConnectorConfigException("Media type must be 'url' or 'path'");
        } elseif ((null === $value) || ("" === $value)) {
            throw new ConnectorConfigException($mediaType . " value is required");
        } elseif ("path" === $mediaType) {
            if (!file_exists($value)) {
                throw new ConnectorConfigException("file doesn't exist");
            } else {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);

                if (!$finfo) {
                    throw new ConnectorConfigException("error getting mime type of the media file");
                }

                $mimeType = $finfo->file($value);

                //$mimeType = $finfo
                if (false === strpos($mimeType, "image/")) {
                    throw new ConnectorConfigException("file must be an image");
                } else {
                    $filesize = filesize($value);
                    if ($filesize > self::MAX_IMPORT_FILE_SIZE) {
                        throw new ConnectorConfigException("Maximum file size is " . (self::MAX_IMPORT_FILE_SIZE_MB) . "MB");
                    }
                }
            }
        } else {
            $tempMedia = tmpfile();
            fwrite($tempMedia, file_get_contents($value));
            $info = stream_get_meta_data($tempMedia);
            $finfo = new \finfo(FILEINFO_MIME_TYPE);

            if (!$finfo) {
                throw new ConnectorConfigException("error getting mime type of the media file");
            }

            $mimeType = $finfo->file($info["uri"]);

            if (false === strpos($mimeType, "image/")) {
                throw new ConnectorConfigException("file must be an image");
            } else {
                $filesize = filesize($info["uri"]);
                if ($filesize > self::MAX_IMPORT_FILE_SIZE) {
                    throw new ConnectorConfigException("Maximum file size is " . (self::MAX_IMPORT_FILE_SIZE_MB) . "MB");
                }
            }
        }

        $parameters = array();
        $parameters["message"] = $title;

        if ("url" === $mediaType) {
            $parameters["url"] = $value;
        } else {
            $parameters["source"] = $this->client->fileToUpload($value);
        }

        try {
            if (null === $albumId) {
                $response = $this->client->post("/".self::FACEBOOK_SELF_USER."/photos", $parameters, $this->accessToken);
            } else {
                $response = $this->client->post("/".$albumId."/photos", $parameters, $this->accessToken);
            }
        } catch (Exception $e) {
            throw new ConnectorServiceException("Error importing '".$value."'': " . $e->getMessage(), $e->getCode());
        }

        $graphNode = $response->getGraphNode();
        $media = array("media_id" => $graphNode["id"]);

        return json_encode($media);
    }


    public function exportMedia($userId, $maxResultsPerPage, $numberOfPages, $pageToken) {
        return;
    }


    /**
     * Service that create a post in Facebook user's feed
     * @param array $parameters
     * @return array
     * @throws ConnectorServiceException
     */
    public function post(array $parameters) {
        try {
            $response = $this->client->post("/".self::FACEBOOK_SELF_USER."/feed", $parameters, $this->accessToken);
        } catch(\Exception $e) {
            throw new ConnectorServiceException('Error creating a post: ' . $e->getMessage(), $e->getCode());
        }

        $graphNode = $response->getGraphNode();

        $post = array("post_id" => $graphNode["id"]);

        return json_encode($post);
    }

    public function getUserRelationship($authenticatedUserId, $userId) {
        return;
    }

    public function modifyUserRelationship($authenticatedUserId, $userId, $action) {
        return;
    }

    public function searchUsers($userId, $name, $maxTotalResults, $numberOfPages, $nextPageUrl) {
        return;
    }

    /**
     * Service that creates a new photo album for the user in facebook
     * @param $userId
     * @param $title
     * @param $caption
     * @return string
     * @throws ConnectorConfigException
     * @throws ConnectorServiceException
     */
    public function createPhotosAlbum($userId, $title, $caption) {
        $this->checkUser($userId);

        $parameters = array();
        $parameters["name"] = $title;
        $parameters["message"] = $caption;

        try {
            $response = $this->client->post("/".self::FACEBOOK_SELF_USER."/albums", $parameters, $this->accessToken);
        } catch (Exception $e) {
            throw new ConnectorServiceException("Error creating album '".$title."'': " . $e->getMessage(), $e->getCode());
        }

        $graphNode = $response->getGraphNode();
        $album = array("album_id" => $graphNode["id"]);

        return json_encode($album);
    }

    /**
     * Service that get information of all user's photo albums in facebook
     * @param $userId
     * @param $maxResultsPerPage
     * @param $numberOfPages
     * @param $pageToken
     * @return string
     * @throws ConnectorConfigException
     * @throws ConnectorServiceException
     */
    public function exportPhotosAlbumsList($userId, $maxResultsPerPage, $numberOfPages, $pageToken) {
        $this->checkUser($userId);
        $this->checkPagination($maxResultsPerPage, $numberOfPages);

        $albums = array();
        $count = 0;
        do {
            try {
                $endpoint = "/".$userId."/albums?limit=".$maxResultsPerPage;
                if ($pageToken) {
                    $endpoint .= "&after=".$pageToken;
                }

                $response = $this->client->get($endpoint, $this->accessToken);

                $albumsEdge = $response->getGraphEdge();

                foreach ($albumsEdge as $album) {
                    $albums[$count][] = $album->asArray();
                }
                $count++;

                $pageToken = $albumsEdge->getNextCursor();

                // If number of pages == 0, then all elements are returned
                if (($numberOfPages > 0) && ($count == $numberOfPages)) {
                    break;
                }
            } catch (Exception $e) {
                throw new ConnectorServiceException("Error exporting photo albums: " . $e->getMessage(), $e->getCode());
                $pageToken = null;
            }
        } while ($pageToken);


        $albums["pageToken"] = $pageToken;

        return json_encode($albums);
    }

    /**
     * Service that gets photos from an album owned by user in facebook
     * @param $userId
     * @param $albumId
     * @param $maxResultsPerPage
     * @param $numberOfPages
     * @param $pageToken
     * @return mixed
     * @throws \Exception
     */
    public function exportPhotosFromAlbum($userId, $albumId, $maxResultsPerPage, $numberOfPages, $pageToken) {
        $this->checkUser($userId);
        $this->checkUser($albumId);
        $this->checkPagination($maxResultsPerPage, $numberOfPages);

        $photos = array();
        $count = 0;
        do {
            try {
                $endpoint = "/".$albumId."/photos?limit=".$maxResultsPerPage;
                if ($pageToken) {
                    $endpoint .= "&after=".$pageToken;
                }

                $response = $this->client->get($endpoint, $this->accessToken);

                $photosEdge = $response->getGraphEdge();

                foreach ($photosEdge as $photo) {
                    $photos[$count][] = $photo->asArray();
                }
                $count++;

                $pageToken = $photosEdge->getNextCursor();

                // If number of pages == 0, then all elements are returned
                if (($numberOfPages > 0) && ($count == $numberOfPages)) {
                    break;
                }
            } catch (Exception $e) {
                throw new ConnectorServiceException("Error exporting photos in album '".$albumId."': " . $e->getMessage(), $e->getCode());
                $pageToken = null;
            }
        } while ($pageToken);


        $photos["pageToken"] = $pageToken;

        return json_encode($photos);
    }

    /**
     * Method that check credentials are present and valid
     * @param array $credentials
     * @throws ConnectorConfigException
     */
    private function checkCredentialsParameters(array $credentials) {
        if ((null === $credentials) || (!is_array($credentials)) || (count($credentials) == 0)) {
            throw new ConnectorConfigException("Invalid credentials set'");
        }

        if ((!isset($credentials["access_token"])) || (null === $credentials["access_token"]) || ("" === $credentials["access_token"])) {
            throw new ConnectorConfigException("'access_token' parameter is required");
        }
    }

    /**
     * Method that check userId is ok
     * @param $userId
     * @throws ConnectorConfigException
     */
    private function checkUser($userId) {
        if ((null === $userId) || ("" === $userId)) {
            throw new ConnectorConfigException("'userId' parameter is required");
        }
    }

    /**
     * Method that check albumId is ok
     * @param $albumId
     * @throws ConnectorConfigException
     */
    private function checkAlbum($albumId) {
        if ((null === $albumId) || ("" === $albumId)) {
            throw new ConnectorConfigException("'albumId' parameter is required");
        }
    }

    /**
     * Method that check pagination parameters are ok
     * @param $maxResultsPerPage
     * @param $numberOfPages
     * @throws ConnectorConfigException
     */
    private function checkPagination($maxResultsPerPage, $numberOfPages) {
        if (null === $maxResultsPerPage) {
            throw new ConnectorConfigException("'maxResultsPerPage' parameter is required");
        } else if (!is_numeric($maxResultsPerPage)) {
            throw new ConnectorConfigException("'maxResultsPerPage' parameter is not numeric");
        }

        if (null === $maxResultsPerPage) {
            throw new ConnectorConfigException("'numberOfPages' parameter is required");
        } else if (!is_numeric($numberOfPages)) {
            throw new ConnectorConfigException("'numberOfPages' parameter is not numeric");
        }
    }

    /**
     * Private function to check url format
     * @param $redirectUrl
     * @return bool
     */
    private function wellFormedUrl($redirectUrl) {
        if (!filter_var($redirectUrl, FILTER_VALIDATE_URL) === false) {
            return true;
        } else {
            return false;
        }
    }
}