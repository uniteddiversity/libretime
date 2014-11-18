<?php

class Rest_MediaController extends Zend_Rest_Controller
{
    const MUSIC_DIRS_STOR_PK = 1;
    
    const IMPORT_STATUS_SUCCESS = 0;
    const IMPORT_STATUS_PENDING = 1;
    const IMPORT_STATUS_FAILED = 2;
    
    //fields that are not modifiable via our RESTful API
    private static $blackList = array(
        'id',
        'directory',
        'filepath',
        'file_exists',
        'mtime',
        'utime',
        'lptime',
        'silan_check',
        'soundcloud_id',
        'is_scheduled',
        'is_playlist'
    );

    public function init()
    {
        $this->view->layout()->disableLayout();

		// Remove reliance on .phtml files to render requests
   		$this->_helper->viewRenderer->setNoRender(true);
    }
    
    public function indexAction()
    {
        if (!$this->verifyAuth(true, true))
        {
            return;
        }
        
        $files_array = array();
        foreach (CcFilesQuery::create()->find() as $file)
        {
            array_push($files_array, CcFiles::sanitizeResponse($file));
        }
        
        $this->getResponse()
        ->setHttpResponseCode(200)
        ->appendBody(json_encode($files_array));
        
        /** TODO: Use this simpler code instead after we upgrade to Propel 1.7 (Airtime 2.6.x branch):
        $this->getResponse()
            ->setHttpResponseCode(200)
            ->appendBody(json_encode(CcFilesQuery::create()->find()->toArray(BasePeer::TYPE_FIELDNAME)));
        */
    }

    public function downloadAction()
    {
        if (!$this->verifyAuth(true, true))
        {
            return;
        }

        $id = $this->getId();
        if (!$id) {
            return;
        }

        $file = CcFilesQuery::create()->findPk($id);
        if ($file) {
            $con = Propel::getConnection();
            $storedFile = new Application_Model_StoredFile($file, $con);
            $baseUrl = Application_Common_OsPath::getBaseDir();

            $this->getResponse()
                ->setHttpResponseCode(200)
                ->appendBody($this->_redirect($storedFile->getRelativeFileUrl($baseUrl).'/download/true'));
        } else {
            $this->fileNotFoundResponse();
        }
    }

    public function clearAction()
    {
        if (!$this->verifyAuth(true, true))
        {
            return;
        }

        //set file_exists flag to false for every file
        $con = Propel::getConnection(CcFilesPeer::DATABASE_NAME);
        $selectCriteria = new Criteria();
        $selectCriteria->add(CcFilesPeer::FILE_EXISTS, true);
        $updateCriteria = new Criteria();
        $updateCriteria->add(CcFilesPeer::FILE_EXISTS, false);
        BasePeer::doUpdate($selectCriteria, $updateCriteria, $con);

        //delete all files and directories under .../imported
        $path = isset($_SERVER['AIRTIME_BASE']) ? $_SERVER['AIRTIME_BASE']."/srv/airtime/stor/imported/*" : "/srv/airtime/stor/imported/*";
        exec("rm -rf $path");

        //update disk_usage value in cc_pref
        $storDir = isset($_SERVER['AIRTIME_BASE']) ? $_SERVER['AIRTIME_BASE']."srv/airtime/stor" : "/srv/airtime/stor";
        $diskUsage = shell_exec("du -sb $storDir | awk '{print $1}'");
        Application_Model_Preference::setDiskUsage($diskUsage);

        $this->getResponse()
            ->setHttpResponseCode(200)
            ->appendBody("Library has been cleared");
    }
    
    public function getAction()
    {
        if (!$this->verifyAuth(true, true))
                {
            return;
        }
        
        $id = $this->getId();
        if (!$id) {
            return;
        }

        $file = CcFilesQuery::create()->findPk($id);
        if ($file) {
            
            $this->getResponse()
                ->setHttpResponseCode(200)
                ->appendBody(json_encode(CcFiles::sanitizeResponse($file)));
        } else {
            $this->fileNotFoundResponse();
        }
    }
    
    public function postAction()
    {
        /*  If the user presents a valid API key, we don't check CSRF tokens.  
            CSRF tokens are only used for session based authentication.
        */
        if(!$this->verifyAPIKey()){
            if(!$this->verifyCSRFToken($this->_getParam('csrf_token'))){
                return;
            }
        }

        if (!$this->verifyAuth(true, true))
        {
            return;
        }

        //If we do get an ID on a POST, then that doesn't make any sense
        //since POST is only for creating.
        if ($id = $this->_getParam('id', false)) {
            $resp = $this->getResponse();
            $resp->setHttpResponseCode(400);
            $resp->appendBody("ERROR: ID should not be specified when using POST. POST is only used for file creation, and an ID will be chosen by Airtime"); 
            return;
        }

        if (Application_Model_Systemstatus::isDiskOverQuota()) {
            $this->getResponse()
                ->setHttpResponseCode(400)
                ->appendBody("ERROR: Disk Quota reached.");
            return;
        }

        $file = new CcFiles();
        $whiteList = $this->removeBlacklistedFieldsFromRequestData($this->getRequest()->getPost());

        if (!$this->validateRequestData($file, $whiteList)) {
            $file->setDbTrackTitle($_FILES["file"]["name"]);
            $file->setDbUtime(new DateTime("now", new DateTimeZone("UTC")));
            $file->save();
            return;
        } else {
            /* If full_path is set, the post request came from ftp.
             * Users are allowed to upload folders via ftp. If this is the case
             * we need to include the folder name with the file name, otherwise
             * files won't get removed from the organize folder.
             */
            if (isset($whiteList["full_path"])) {
                $fullPath = $whiteList["full_path"];
                $basePath = isset($_SERVER['AIRTIME_BASE']) ? $_SERVER['AIRTIME_BASE']."/srv/airtime/stor/organize/" : "/srv/airtime/stor/organize/";
                //$relativePath is the folder name(if one) + track name, that was uploaded via ftp
                $relativePath = substr($fullPath, strlen($basePath)-1);
            } else {
                $relativePath = $_FILES["file"]["name"];
            }

            
            $file->fromArray($whiteList);
            $file->setDbOwnerId($this->getOwnerId());
            $now  = new DateTime("now", new DateTimeZone("UTC"));
            $file->setDbTrackTitle($_FILES["file"]["name"]);
            $file->setDbUtime($now);
            $file->setDbHidden(true);
            $file->save();

            $callbackUrl = $this->getRequest()->getScheme() . '://' . $this->getRequest()->getHttpHost() . $this->getRequest()->getRequestUri() . "/" . $file->getPrimaryKey();

            $this->processUploadedFile($callbackUrl, $relativePath, $this->getOwnerId());

            $this->getResponse()
                ->setHttpResponseCode(201)
                ->appendBody(json_encode(CcFiles::sanitizeResponse($file)));
        }
    }

    public function putAction()
    {
        if (!$this->verifyAuth(true, true))
        {
            return;
        }
        
        $id = $this->getId();
        if (!$id) {
            return;
        }
        
        $file = CcFilesQuery::create()->findPk($id);

        $requestData = json_decode($this->getRequest()->getRawBody(), true);
        $whiteList = $this->removeBlacklistedFieldsFromRequestData($requestData);
        $whiteList = $this->stripTimeStampFromYearTag($whiteList);

        if (!$this->validateRequestData($file, $whiteList)) {
            $file->save();
            return;
        } else if ($file && isset($requestData["resource_id"])) {
            $file->fromArray($whiteList, BasePeer::TYPE_FIELDNAME);
            
            //store the original filename
            $file->setDbFilepath($requestData["filename"]);
            
            $fileSizeBytes = $requestData["filesize"];
            if (!isset($fileSizeBytes) || $fileSizeBytes === false)
            {
                $file->setDbImportStatus(2)->save();
                $this->fileNotFoundResponse();
                return;
            }
            $cloudFile = new CloudFile();
            $cloudFile->setResourceId($requestData["resource_id"]);
            $cloudFile->setCcFiles($file);
            $cloudFile->save();
            
            Application_Model_Preference::updateDiskUsage($fileSizeBytes);
            
            $now  = new DateTime("now", new DateTimeZone("UTC"));
            $file->setDbMtime($now);
            $file->save();
            
            $this->getResponse()
                ->setHttpResponseCode(200)
                ->appendBody(json_encode(CcFiles::sanitizeResponse($file)));
        } else {
            $file->setDbImportStatus(self::IMPORT_STATUS_FAILED)->save();
            $this->fileNotFoundResponse();
        }
    }

    public function deleteAction()
    {
        if (!$this->verifyAuth(true, true))
        {
            return;
        }
        
            
        $id = $this->getId();
        if (!$id) {
            return;
        }
        $file = CcFilesQuery::create()->findPk($id);
        if ($file) {
            $con = Propel::getConnection();
            $storedFile = Application_Model_StoredFile::RecallById($id, $con);
            $storedFile->delete(); //TODO: This checks your session permissions... Make it work without a session?
            
            $this->getResponse()
                ->setHttpResponseCode(204);
        } else {
            $this->fileNotFoundResponse();
        }
    }

    private function getId()
    {
        if (!$id = $this->_getParam('id', false)) {
            $resp = $this->getResponse();
            $resp->setHttpResponseCode(400);
            $resp->appendBody("ERROR: No file ID specified."); 
            return false;
        } 
        return $id;
    }

    private function verifyCSRFToken($token){
        $current_namespace = new Zend_Session_Namespace('csrf_namespace');
        $observed_csrf_token = $token;
        $expected_csrf_token = $current_namespace->authtoken;

        if($observed_csrf_token == $expected_csrf_token){
            return true;
        }else{
            return false;
        }
    }

    private function verifyAuth($checkApiKey, $checkSession)
    {
        //  Session takes precedence over API key for now:
        if ($checkSession && $this->verifySession()) {
            //  CSRF token validation only applies to session based authorization.
            if(!$this->verifyCSRFToken($this->_getParam('csrf_token'))){
                $resp = $this->getResponse();
                $resp->setHttpResponseCode(401);
                $resp->appendBody("ERROR: Token Missmatch."); 
                return false;
            }
            return true;
        }
        
        if ($checkApiKey && $this->verifyAPIKey())
        {
            return true;
        }
        
        $resp = $this->getResponse();
        $resp->setHttpResponseCode(401);
        $resp->appendBody("ERROR: Incorrect API key.");
               
        return false;
    }
    
    private function verifyAPIKey()
    {
        //The API key is passed in via HTTP "basic authentication":
        // http://en.wikipedia.org/wiki/Basic_access_authentication

        $CC_CONFIG = Config::getConfig();

        //Decode the API key that was passed to us in the HTTP request.
        $authHeader = $this->getRequest()->getHeader("Authorization");
        $encodedRequestApiKey = substr($authHeader, strlen("Basic "));
        $encodedStoredApiKey = base64_encode($CC_CONFIG["apiKey"][0] . ":");
        
        if ($encodedRequestApiKey === $encodedStoredApiKey) 
        {
            return true;
        } else {
            return false;
        }
        
        return false;
    }
    
    private function verifySession()
    {
        $auth = Zend_Auth::getInstance();
        if ($auth->hasIdentity())
        {
            return true;
        }
        return false;
        
        //Token checking stub code. We'd need to change LoginController.php to generate a token too, but
        //but luckily all the token code already exists and works.
        //$auth = new Application_Model_Auth();
        //$auth->checkToken(Application_Model_Preference::getUserId(), $token);
    }

    private function fileNotFoundResponse()
    {
        $resp = $this->getResponse();
        $resp->setHttpResponseCode(404);
        $resp->appendBody("ERROR: Media not found."); 
    }
    
    private function importFailedResponse()
    {
        $resp = $this->getResponse();
        $resp->setHttpResponseCode(200);
        $resp->appendBody("ERROR: Import Failed.");
    }

    private function invalidDataResponse()
    {
        $resp = $this->getResponse();
        $resp->setHttpResponseCode(422);
        $resp->appendBody("ERROR: Invalid data");
    }

    private function validateRequestData($file, &$whiteList)
    {
        try {        
            // EditAudioMD form is used here for validation
            $fileForm = new Application_Form_EditAudioMD();
            $fileForm->startForm($file->getDbId());
            $fileForm->populate($whiteList);
            
            /*
             * Here we are truncating metadata of any characters greater than the
             * max string length set in the database. In the rare case a track's
             * genre is more than 64 chars, for example, we don't want to reject
             * tracks for that reason
             */
            foreach($whiteList as $tag => &$value) {
                if ($fileForm->getElement($tag)) {
                    $stringLengthValidator = $fileForm->getElement($tag)->getValidator('StringLength');
                    //$stringLengthValidator will be false if the StringLength validator doesn't exist on the current element
                    //in which case we don't have to truncate the extra characters
                    if ($stringLengthValidator) {
                        $value = substr($value, 0, $stringLengthValidator->getMax());
                    }
                    
                    $value = $this->stripInvalidUtf8Characters($value);
                }
            }
    
            if (!$fileForm->isValidPartial($whiteList)) {
                throw new Exception("Data validation failed");
            }
        } catch (Exception $e) {
            $errors = $fileForm->getErrors();
            $messages = $fileForm->getMessages();
            Logging::error($messages);
            $file->setDbImportStatus(2);
            $file->setDbHidden(true);
            $this->invalidDataResponse();
            return false;
        }
        return true;
    }

    private function processUploadedFile($callbackUrl, $originalFilename, $ownerId)
    {
        $CC_CONFIG = Config::getConfig();
        $apiKey = $CC_CONFIG["apiKey"][0];
                
        $tempFilePath = $_FILES['file']['tmp_name'];
        $tempFileName = basename($tempFilePath);

        //Only accept files with a file extension that we support.
        $fileExtension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        if (!in_array(strtolower($fileExtension), explode(",", "ogg,mp3,oga,flac,wav,m4a,mp4,opus")))
        {
            @unlink($tempFilePath);
            throw new Exception("Bad file extension.");
        }

        //TODO: Remove uploadFileAction from ApiController.php **IMPORTANT** - It's used by the recorder daemon...
         
        $storDir = Application_Model_MusicDir::getStorDir();
        $importedStorageDirectory = $storDir->getDirectory() . "/imported/" . $ownerId;
        
        try {
            //Copy the temporary file over to the "organize" folder so that it's off our webserver
            //and accessible by airtime_analyzer which could be running on a different machine.
            $newTempFilePath = Application_Model_StoredFile::copyFileToStor($tempFilePath, $originalFilename);
        } catch (Exception $e) {
            @unlink($tempFilePath);
            Logging::error($e->getMessage());
            return;
        }

        //Dispatch a message to airtime_analyzer through RabbitMQ,
        //notifying it that there's a new upload to process!
        Application_Model_RabbitMq::SendMessageToAnalyzer($newTempFilePath,
                 $importedStorageDirectory, basename($originalFilename),
                 $callbackUrl, $apiKey);
    }
    
    private function getOwnerId()
    {
        try {
            if ($this->verifySession()) {
                $service_user = new Application_Service_UserService();
                return $service_user->getCurrentUser()->getDbId();
            } else {
                $defaultOwner = CcSubjsQuery::create()
                    ->filterByDbType('A')
                    ->orderByDbId()
                    ->findOne();
                if (!$defaultOwner) {
                    // what to do if there is no admin user?
                    // should we handle this case?
                    return null;
                }
                return $defaultOwner->getDbId();
            }
        } catch(Exception $e) {
            Logging::info($e->getMessage());
        }
    }

    /**
     * 
     * Strips out fields from incoming request data that should never be modified
     * from outside of Airtime
     * @param array $data
     */
    private static function removeBlacklistedFieldsFromRequestData($data)
    {
        foreach (self::$blackList as $key) {
            unset($data[$key]);
        }
    
            return $data;
        }


    private function removeEmptySubFolders($path)
    {
        exec("find $path -empty -type d -delete");
    }

    /*
     * It's possible that the year tag will be a timestamp but Airtime doesn't support this.
     * The year field in cc_files can only be 16 chars max.
     * 
     * This functions strips the year field of it's timestamp, if one, and leaves just the year
     */
    private function stripTimeStampFromYearTag($metadata)
    {
        if (isset($metadata["year"])) {
            if (preg_match("/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}):(\d{2}))?$/", $metadata["year"])) {
                $metadata["year"] = substr($metadata["year"], 0, 4);
            }
        }
        return $metadata;
    }
    
    private function stripInvalidUtf8Characters($string)
    {
        //Remove invalid UTF-8 characters
        //reject overly long 2 byte sequences, as well as characters above U+10000 and replace with ?
        $string = preg_replace('/[\x00-\x08\x10\x0B\x0C\x0E-\x19\x7F]'.
                '|[\x00-\x7F][\x80-\xBF]+'.
                '|([\xC0\xC1]|[\xF0-\xFF])[\x80-\xBF]*'.
                '|[\xC2-\xDF]((?![\x80-\xBF])|[\x80-\xBF]{2,})'.
                '|[\xE0-\xEF](([\x80-\xBF](?![\x80-\xBF]))|(?![\x80-\xBF]{2})|[\x80-\xBF]{3,})/S',
                '?', $string );
         
        //reject overly long 3 byte sequences and UTF-16 surrogates and replace with ?
        $string = preg_replace('/\xE0[\x80-\x9F][\x80-\xBF]'.
                '|\xED[\xA0-\xBF][\x80-\xBF]/S','?', $string );
        
        //Do a final encoding conversion to
        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        return $string;   
    }
}
