<?php

namespace [YOUR_NAMESPACE]\Controller;

use Aws\S3\S3Client;
use Monolog\Logger;
use [YOUR_NAMESPACE]\Entity\Upload;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use [YOUR_NAMESPACE]\Repository\UploadRepository;


class UploadController extends Controller
{
    const MAX_SIGNS_PER_24H = 2500; //max signs per user per 24h
    /**
     * The new action should be called if the user wants to start uploading a new file
     * It will provide a filename to which the user can upload the file
     * @param string $filename
     * @param int    $chuncked
     * @return JsonResponse
     */
    public function newAction($filename, $chunked, Request $request)
    {
        $this->forceCanSign(); //make sure user is not exceeding number of sings in last 24h

        //get extension of original filename, so we can reuse it
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if ($ext == '') {
            $ext .= ".tmp";
        }

        //i prefer a uuid but this should be installed as seperate php extension
        $uniqueKey = method_exists('uuid_create') ? uuid_create() : uniqid();

        //create new random filename, we have to be sure it has not been used before
        $s3key = strtolower($uniqueKey).".".$ext;

        //create new upload entity, so we can track and limit user uploads
        $up = Upload::createNew($this->getUser(),$s3key);
        $this->getUploadRepository()->save($up);


        //get full filename if chunked
        if ($chunked > 0) {
            $fn = $s3key.".0";
        } else {
            $fn = $s3key;
        }

        $p = $this->createPolicy($fn);
        $s = $this->sign($p);


        //It probably is a good idea to add some logging behaviour so you are able to check for security vulnerabilities
        //And other possible malfunctioning
        $msg = sprintf("Create new file %s with s3 file name %s and client filename %s", $chunked ? 'chuncked' : 'unchunked', $s3key, $filename);
        $this->getLogger()->info($msg,['user' => $this->getUser()->getEmail(),'ip' => $request->getClientIp(), "agent" => $request->headers->get('User-Agent')]);
        

        $data = ['filename' => $s3key, 'policy' => $p, 'signature' => $s];
        return new JsonResponse($data);
    }

    /**
     * Creates a signature for a chunk
     * This function does not enforce the chunks to be signed in succesive order
     * This makes that they can be signed in any given order
     * @param string    $filename 
     * @param int       $chunk
     * @param Request   $request
     * @return JsonResponse
     */
    public function chunkAction($filename, $chunk, Request $request)
    {
        
        $this->forceCanSign(); //make sure user is not exceeding number of sings in last 24h
        $chunk = (int) $chunk;
        $upload = $this->getUploadRepository()->findByFilename($filename, $this->getUser());
        //if user is requesting to sign a chunk for a filename that was not assigned to that user, refuse to sign
        if ($upload === null) {
            throw $this->createNotFoundException();
        }

        //add to counter
        $this->getUploadRepository()->signNext($upload);
        $fn = $filename.".".$chunk;

        $p = $this->createPolicy($fn);
        $s = $this->sign($p);

        $msg = sprintf("Signed new chunk %d with s3 file name %s", $chunk, $filename);
        $this->getLogger()->info($msg,['user' => $this->getUser()->getEmail(),'ip' => $request->getClientIp(), "agent" => $request->headers->get('User-Agent')]);
        
        $data = ['filename' => $fn, 'policy' => $p, 'signature' => $s];
        return new JsonResponse($data);

    }

    /**
     * Once all chunks are uploaded the following function should be called to make sure that file is merged
     * @param string $filename
     * @param int    $chunks
     * @param Request $request
     * @return JsonResponse
     */
    public function mergeAction($filename, $chunks, Request $request)
    {
        $upload = $this->getUploadRepository()->findByFilename($filename, $this->getUser());

        if ($upload === null) {
            throw $this->createNotFoundException();
        }
        $chunks = (int) $chunks;
        //if you want to merge the file via a cronjob you have to save the number of chunks
        $upload->setChunks($chunks);
        $upload->setDoneTime(new \DateTime("now"));

        $this->getUploadRepository()->save($upload);

        //merge chunks on aws s3
        $this->merge($filename, $chunks);

        $msg = sprintf("Succesfully finalized and merged file %s ", $filename);
        $this->getLogger()->info($msg,['user' => $this->getUser()->getEmail(),'ip' => $request->getClientIp(), "agent" => $request->headers->get('User-Agent')]);
        return new JsonResponse($data);
    }

    /**
     * This function actually merges the chunks into one single file on amazon using the MutliPart upload request
     * In my own implementation I actually did this via a cronjob. But for the purpose of this tutorial I will do it 
     * on the request
     * @param string $filename
     * @param int    $chunks
     */
    protected function merge($filename, $chunks)
    {
        //Skip if file does not have to be merged
        if ($chunks > 0) {
            //We make use the aws SDK s3 client implementation
            $client = $this->getS3Client();
            $bucket = $this->getContainer()->getParameter('s3_uploader_bucket');

            //Indicate that you want to merge a file into $filename
            $response = $client->createMultipartUpload(['Bucket' => $bucket, 'Key' => $filename]);
            $data = ['filename' => $filename, "UploadId" => $response['UploadId']];

            $objects = [];

            //assemble list of all parts to merge
            for ($c =0; $c < $chunks; $c++) {
                $objects[] = ['Key' => $filename.".".$c];
                $client->uploadPartCopy([
                    'CopySource' => $bucket."/".$filename.".".$c,
                    'Bucket' => $bucket,
                    'Key' => $filename,
                    'UploadId' => $data['UploadId'],
                    'PartNumber' => $c + 1]);

            }

            //pass list to multi part upload command
            $partsModel = $client->listParts(array(
                'Bucket' => $bucket,
                'Key'       => $filename,
                'UploadId'  => $data['UploadId']
            ));

            //make sure to finalize
            $model = $client->completeMultipartUpload(array(
                'Bucket' => $bucket,
                'Key' => $filename,
                'UploadId' => $data['UploadId'],
                'MultipartUpload' => [
                    'Parts' => $partsModel['Parts']
                ]
            ));

            //delete the old chunks afterwards
            $client->deleteObjects(
                [
                    'Bucket' => $bucket,
                    'Delete' => [
                        'Objects' => $objects
                    ]
                ]
            );
        }
    }

    /**
     * Enforces that the number of signs for a given user does not exceed the maximum for the last 24 hours
     * @throws AccessDeniedException 
     */
    protected function forceCanSign()
    {
        if ($this->getUploadRepository()->signsLast24h($this->getUser()) > self::MAX_SIGNS_PER_24H) {
            throw new AccessDeniedException();
        }
    }

    /**
     * This defined the constraints on a user request, this policy will also be passed with the 
     * upload request. Given this policy and your key amazon will then rebuild your signature. If the 
     * signatures matches it means that someone with access to your key has approved this  
     */
    protected function createPolicy($fn)
    {
        $d = new \DateTime();
        $d->modify('+1 days');
        $policy = [
            'expiration' => $d->format('Y-m-d\TG:i:s\Z'),//signature expires within 1 day
            'conditions' => [
                ['bucket' => $this->getParameter('s3_uploader_bucket')],
                ['acl' => 'private'],
                ['content-length-range', 0, 10485760],//max size to upload can be 10mb
                ['key' => $fn],//the policy also enforces a specific key(amazon s3 language for file), only allowing the user to upload to this filename
                ['success_action_status' => '200'],//after upload respone with 200 status code
                [ "starts-with", '$Content-Type', "video/" ],//i only want to upload video files
                [ "starts-with", '$name', "" ],
                [ "starts-with", '$chunk', "" ],
                [ "starts-with", '$chunks', "" ]
            ]
        ];
        return base64_encode(json_encode($policy));
    }

    /**
     * Signs actual policy (obtained by createPolicy) 
     */
    protected function sign($policyStr)
    {
        return base64_encode(hash_hmac(
            'sha1',
            $policyStr,
            $this->getParameter('s3_uploader_key'),
            true
        ));
    }

    //some methods that get services from the container
    //so that my IDE can autocomplete

    /**
     * @return UploadRepository
     */
    protected function getUploadRepository()
    {
        return $this->getDoctrine()->getRepository('[YOUR_NAMESPACE]\Entity\Upload');
    }

    /**
     * @return S3Client
     */
    protected function getS3Client()
    {
        return $this->get("video_upload.s3_client");
    }

    /**
     * @return Logger
     */
    protected function getLogger()
    {
        return $this->get('monolog.logger.uploader');
    }
}