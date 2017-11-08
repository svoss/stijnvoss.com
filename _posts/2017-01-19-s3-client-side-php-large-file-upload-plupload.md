---
layout: post
title: Large file uploading directly to amazon s3 using chunking in PHP symfony
permalink: /chuncked-client-side-upload-s3-php-symfony.html
lang: en
---
## Uploading video content 
Recently I was working on a project where users could share a video on a web application to a limited set of users. To make sure that videos can be played inside a browser using HTML5, these video will have to be converted. 

The amazon AWS platform offers a nice set of services that I could use to build this application. To convert videos I make use of [Amazon ES](https://aws.amazon.com/elastictranscoder/). Which is able to convert videos from one bucket to another bucket. Using signed s3 links I could also make sure that converted videos could only be retrieved by the users with access to this video. 

## Amazon s3 browser based uploading

However uploading large files to your own web server can be quite a hassle to get right. A lot of security and scalability issues may arise. Amazon s3 supports [browser based uploading](http://docs.aws.amazon.com/AmazonS3/latest/dev/UsingHTTPPOST.html). This gives you the opportunity to let your user upload the files directly to your s3 bucket, without the need to upload the file to your own server first. This solution especially makes sense when you already are planning on saving your files on amazon s3, like I was.

## Multipart uploading
Videos usually are large files however. And uploading large files at once has some difficulties, sometimes the upload might fail and you will have to upload the file again entirely. To prevent this you can make use file of chuncking, a large file is then split into smaller parts and uploaded separately. Once all parts are uploaded you combine them  again on your web server to reconstruct the original file. Whenever your upload fails you can just re-upload a single part instead of the whole file. It also gives you the opportunity to pause and resume your uploads.

To merge the files on amazon s3 you can make use of the [amazon multipart](http://docs.aws.amazon.com/AmazonS3/latest/dev/uploadobjusingmpu.html) functionality.

## Signing
You probably don't want everyone to be able to upload an unlimited number of files to your bucket. Therefore every request that the user makes has to be accompanied by a signature. This signature should be provided on your own web server. After the web server made sure that the user should be able to upload files. It can generate the signature by hashing certain constraints on the request(like file name, allowed mime types and maximum file size) together with your aws key. When amazon receives the request it will do the same. When the signatures are equal it proofs that someone or something with access to your key has signed the request of the user. 

## PHP symfony + plupload
I will now show how you can implement this using PHP symfony and [plupload](http://www.plupload.com/). The latter is a javascript libary that will help us with the chunking and other aspects of file uploading on the browser side.  

Once a user starts uploading a new file plupload will make a request to the web server indicating that it wants to upload a new file. After some checks the the server will respond with a filename and a corresponding signature for the first chunk. Depending on the clients file size plupload will divide the file in one or more chunks.  For every succesive chunk a request will be made to the web server asking for a new signature for that specific chunk. At the same time the server will keep some administration to keep track of which files are signed by which user and how many chunks have been signed for.  

In my case my web server will check if the user is correctly authenticated, since we want only authenticated users to upload videos. It also limits the number of sign requests a user can make in 24 hours to prevent the mallicious users from uploading huge amounts of files. 

After all chunks have been uploaded the client will send a merge request to the server. The server will flag the upload as finished. Using a cronjob the chunks will then be merged with a multipart request and submited to the Amazon ES service for conversion. The multipart request can also be done directly on the users merge request. However I found that the multipart request can take quite some time for large files. To improve user experience I decided to work with a cronjob. 

### Installing dependencies
For the multipart request we will make use of the aws SDK, which you can install via composer.
{% highlight bash %}
composer require aws/aws-sdk-php
{% endhighlight %}
### Configuring amazon users and s3 buckets
Next login to the amazon console and create a new bucket in my case I will call it `my-video-upload-bucket`, you can of course also re-use an existing bucket but you have to be sure no file name collisions can occur. 

#### Create upload user 
I recommend creating a new user under the IAM panel that has the sole purpose of signing upload requests to your bucket. The user only needs programmatic access. In my case I called this user `uploader`. Make sure that your user also has PutObject access to your bucket. Which can be done by attaching the following policy:

{% highlight json linenos%}
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "Stmt1470746880000",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject"
            ],
            "Resource": [
                "arn:aws:s3:::my-video-upload-bucket/"
            ]
        }
    ]
}
{% endhighlight %}
Make sure that you replace `my-video-upload-bucket` with the name of your own bucket. 

Lastly save your bucket name, user key id and secret access key in your parameters.yml file. {% highlight yaml %}
parameters:
    s3_uploader_id: ...
    s3_uploader_key: ...
    s3_uploader_bucket: my-video-upload-bucket
{% endhighlight %} 

#### SDK user for multipart request
The php sdk should be configured to make use of another user account.  Please make sure that this user has permissions to perform the `multipart` request on the s3 bucket. 

To do the multipart we will make use of the `Aws\S3\S3Client` class. In `services.yml` I register it like this:
{% highlight yaml %}
video_upload.s3_client:
    class: Aws\S3\S3Client
    arguments: [%aws_creds%]
    factory: ['Aws\S3\S3Client','factory']
{% endhighlight %} 

In my parameters.yml file the aws_creds variable looks like this:

{% highlight yaml %}
parameters:
    aws_creds:
        profile: ***
        region: eu-west-1
        version: latest
{% endhighlight %} 
But this might differ depending on the way you configure your php sdk. More information about configuring your sdk can be found [here](http://docs.aws.amazon.com/aws-sdk-php/v3/guide/getting-started/basic-usage.html#usage-summary).
### Administration
To limit the number of signs per user we create the following entity. It keeps track of the number signs, chunks and signs dates. It could also be used to clean up unused uploads.
{% highlight php linenos %}
{% include_relative chunked-s3/upload-entity.php %}
{% endhighlight %}
Our repository, responsible for retrieving, changing and saving the entities, will then look like this:
{% highlight php %}
{% include_relative chunked-s3/repository.php %}
{% endhighlight %}

### Controllers and signing proces
This is the controller that is responsible for signing the plupload ajax requests:
{% highlight php linenos %}
{% include_relative chunked-s3/controller.php %}
{% endhighlight %}
Also make sure to register your routes
{% highlight yaml %}
upload_sign_new:
  pattern: /upload/sign/new/{filename}/{chunked}
  defaults: {_controller: SMKvvbBundle:Upload:new}
  methods: [POST]

upload_sign_chunk:
  pattern: /upload/sign/chunk/{filename}/{chunk}
  defaults: {_controller: SMKvvbBundle:Upload:chunk}
  methods: [POST]

upload_merge:
  pattern: /upload/merge/{filename}/{chunks}
  defaults: {_controller: SMKvvbBundle:Upload:merge}
  methods: [POST]
{% endhighlight %}

Dont forget to make sure that a user is logged in when calling one of these routes by adding to your `security.yml` something like:

{% highlight yaml %}
access_control:
    - { path: ^/upload/, role: ROLE_USER}
{% endhighlight %}

### Configuring plupload
Next we have to make sure plupload will perform the requests so that our uploads are signed. The javascript is obtained from [Ben Nadel](https://www.bennadel.com/blog/2586-chunking-amazon-s3-file-uploads-with-plupload-and-coldfusion.htm), but I made some small changes. This script assumes that your have included Jquery and plupload in your html page. You can download plupload [here](http://www.plupload.com/)
{% highlight javascript linenos %}
{% include_relative chunked-s3/plupload.js %}
{% endhighlight %}
### Viewing the upload form
In your view template you have to make sure that the paths that plupload will have to request are available . We also have to provide amazon access id and the url to our bucket, which will be used as the URL to post the final upload request to. We make use of the default symfony path() function to generate our sign and merge paths but put `_chunk_` and `_filename_` as our variables. These can then be easily replaced by our plupload javascript functions. 
{% highlight twig%}
{% raw %}
<a id="select-file" 
     data-action="https://{{ upload_bucket  }}.s3.amazonaws.com/" 
     data-aws-access-id="{{ aws_access_id }}" 
     data-merge="{{ path('upload_merge',{'filename':"_filename_",'chunks':'_chunks_'}) }}" 
     data-sign-chunk="{{ path('upload_sign_chunk', {'filename':"_filename_", 'chunk': '_chunk_'}) }}" 
     data-sign-new="{{ path('upload_sign_new', {'filename':"_filename_", "chunked": "_chunked_"}) }}" 
     data-url-progress="{{ path('posts_upload_video_progress',{'id':'_id'})}}"
>Select file</a>
{% endraw %}
{% endhighlight %}

Make sure you pass the access_id and upload_bucket to your template, by doing something like this in your controller:
{% highlight php %}
<?php
 return $this->render('....', [
            'upload_bucket' => $this->getParameter('s3_uploader_bucket'),
            'aws_access_id' => $this->getParameter('s3_uploader_key'),
    ]);
?>
{% endhighlight %}


That's it. I hope you found this tutorial helpful. If you have any questions our comments, please let me know below.