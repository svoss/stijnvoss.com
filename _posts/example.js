var uploader = new plupload.Uploader({
    runtimes : 'html5,flash',
    browse_button : 'select-file', // you can pass in id...
    container: document.getElementById('container'), // ... or DOM Element itself
    url : $('#select-file').data('action'),
    flash_swf_url : '../js/Moxie.swf',
    filters : {
        max_file_size : '1500mb',
        mime_types: [
            {title : "Video files", extensions : "mp4,mov,mpeg,mpg,avi,mkv,mts,3gp,m4v"},//allowed extensions
        ]
    },
    urlstream_upload: true,
    file_data_name: "file", //the name of the POST field that constains the file, s3 expects this to be 'file'
    max_retries: 3,
    multipart: true,
    chunk_size:'10mb', //All chunks have to be >5mb for amazon s3 to accept, default plupload does not support this: hence files of 7mb are splitted in 5 and 2mb
    multipart_params: {
        "acl": "private",
        "AWSAccessKeyId": $('#select-file').data('aws-access-id') ,
        "Content-Type": "video/*",
        "success_action_status": 200

    }
});

//event handlers:
//once file is finished make sure to merge it
function hFileUploaded(up, file, object){
   merge(file.s3Key, file.chunkIndex);
}
uploader.bind("FileUploaded", hFileUploaded);

function hUploadProgress(up, file) {
    //track progress
}
uploader.bind('UploadProgress', hUploadProgress);

function hError(up, err) {
    if(err.code == -601) {
       // When file extension is not allowed
    } else {
        //other error
    }
}
uploader.bind('Error',hError);

function hFilesAdded(up, files) {
    plupload.each(files, function (file) {
        uploader.start();
    });
}
uploader.bind('FilesAdded', hFilesAdded);

//Will sign a new filename
function signNew(filename, chunked)
{
    var strChunked = chunked ? "1" : "0";
    var u = $('#select-file').data('sign-new');
    u = u.replace("_filename_",filename);
    u = u.replace("_chunked_", strChunked);

    var data;
    $.ajax({
        url: u,
        method:'POST',
        success: function (result) {
            data = result;
        },
        async: false //we have to sign before we can do the request
    });
    //data will contain file name and signature
    return data;


}
//will sign a chunk of a file
function signChunk(filename, chunk)
{
    var u = $('#select-file').data('sign-chunk');
    u = u.replace("_filename_",filename);
    u = u.replace("_chunk_", chunk);
    var data;
    $.ajax({
        url: u,
        method:'POST',
        success: function (result) {
            data = result;
        },
        async: false //we have to sign before we can do the request
    });
    return data;
}
//merges file
function merge(filename, chunks)
{
    if (typeof chunks === 'undefined') {
        chunks = 0;
    }
    var u = $('#select-file').data('merge');
    u = u.replace("_filename_",filename);
    u = u.replace("_chunks_", chunks);

    var data;
    $.ajax({
        url: u,
        method:'POST',
        success: function (result) {
            data = result;
        },
        async: true
    });
    return data;
}
//signs files that are < 5mb or the first chunk
function hBeforeUpload( uploader, file ) {
    console.log( "File upload about to start.", file.name );
    // Track the chunking status of the file (for the success handler). With
    // Amazon S3, we can only chunk files if the leading chunks are at least
    // 5MB in size.
    file.isChunked = isFileSizeChunkableOnS3( file.size );

    // we do our first signing, which determines the filename of this file
    var signature = signNew(file.name, file.isChunked);

    file.s3Key = signature.filename;

    uploader.settings.multipart_params.signature = signature.signature;
    uploader.settings.multipart_params.policy = signature.policy;
    // This file can be chunked on S3 - at least 5MB in size.
    if ( file.isChunked ) {
        // Since this file is going to be chunked, we'll need to update the
        // chunk index every time a chunk is uploaded. We'll start it at zero
        // and then increment it on each successful chunk upload.
        file.chunkIndex = 0;
        // Create the chunk-based S3 resource by appending the chunk index.
        file.chunkKey = ( file.s3Key + "." + file.chunkIndex );
        // Define the chunk size - this is what tells Plupload that the file
        // should be chunked. In this case, we are using 5MB because anything
        // smaller will be rejected by S3 later when we try to combine them.
        // --
        // NOTE: Once the Plupload settings are defined, we can't just use the
        // specialized size values - we actually have to pass in the parsed
        // value (which is just the byte-size of the chunk).
        uploader.settings.chunk_size = plupload.parseSize( "5mb" );
        // Since we're chunking the file, Plupload will take care of the
        // chunking. As such, delete any artifacts from our non-chunked
        // uploads (see ELSE statement).
        delete( uploader.settings.multipart_params.chunks );
        delete( uploader.settings.multipart_params.chunk );
        // Update the Key and Filename so that Amazon S3 will store the
        // CHUNK resource at the correct location.
        uploader.settings.multipart_params.key = file.chunkKey;
        // This file CANNOT be chunked on S3 - it's not large enough for S3's
        // multi-upload resource constraints
    } else {
        // Remove the chunk size from the settings - this is what tells
        // Plupload that this file should NOT be chunked (ie, that it should
        // be uploaded as a single POST).
        uploader.settings.chunk_size = 0;
        // That said, in order to keep with the generated S3 policy, we still
        // need to have the chunk "keys" in the POST. As such, we'll append
        // them as additional multi-part parameters.
        uploader.settings.multipart_params.chunks = 0;
        uploader.settings.multipart_params.chunk = 0;
        // Update the Key and Filename so that Amazon S3 will store the
        // base resource at the correct location.
        uploader.settings.multipart_params.key = file.s3Key;
    }
}


//sign each chunk that's not the first
function hChunkUploaded( uploader, file, info ) {
    console.log( "Chunk uploaded.", info.offset, "of", info.total, "bytes." );
    // As the chunks are uploaded, we need to change the target location of
    // the next chunk on Amazon S3. As such, we'll pre-increment the chunk
    file.chunkKey = ( file.s3Key + "." + ++file.chunkIndex );

    //next sign next chunck
    signature = signChunk(file.s3Key, file.chunkIndex );
    uploader.settings.multipart_params.signature = signature.signature;
    uploader.settings.multipart_params.policy = signature.policy;
    delete( uploader.settings.multipart_params.chunks );
    delete( uploader.settings.multipart_params.chunk );
    // Update the Amazon S3 chunk keys. By changing them here, Plupload will
    // automatically pick up the changes and apply them to the next chunk that
    // it uploads.
    uploader.settings.multipart_params.key = file.chunkKey;
}

// I determine if the given file size (in bytes) is large enough to allow
// for chunking on Amazon S3 (which requires each chunk by the last to be a
// minimum of 5MB in size).
function isFileSizeChunkableOnS3( fileSize ) {
    var KB = 1024;
    var MB = ( KB * 1024 );
    var minSize = ( MB * 5 );
    return( fileSize > minSize );
}
uploader.bind( "BeforeUpload", hBeforeUpload );
uploader.bind( "ChunkUploaded", hChunkUploaded );
uploader.init();