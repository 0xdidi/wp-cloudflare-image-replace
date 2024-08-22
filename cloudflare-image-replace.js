jQuery(document).ready(function($) {
    var interval;

    // Start/Stop button logic
    $('#cloudflare-image-replace-button').on('click', function() {
        var $button = $(this);
        $.ajax({
            url: cloudflareImageReplaceAjax.ajax_url,
            method: 'POST',
            data: {
                action: 'toggle_image_replace',
                nonce: cloudflareImageReplaceAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.status === 'started') {
                        $button.text('Stop Image Replacement');
                        startProgressTracking();
                    } else if (response.data.status === 'stopped') {
                        $button.text('Start Image Replacement');
                        stopProgressTracking();
                    }
                } else {
                    alert('Something went wrong! Please try again.');
                }
            }
        });
    });

    // Function to start progress tracking
    function startProgressTracking() {
        interval = setInterval(function() {
            $.ajax({
                url: cloudflareImageReplaceAjax.ajax_url,
                method: 'POST',
                data: {
                    action: 'get_image_replace_progress',
                    nonce: cloudflareImageReplaceAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var totalImages = response.data.total_images;
                        var processedImages = response.data.processed_images;
                        var successfulImages = response.data.successful_images;
                        var failedImages = response.data.failed_images;

                        // Update counters
                        $('#total-images').text(totalImages);
                        $('#processed-images').text(processedImages);
                        $('#successful-images').text(successfulImages);
                        $('#failed-images').text(failedImages);

                        // Update progress bar
                        if (totalImages > 0) {
                            var progressPercentage = (processedImages / totalImages) * 100;
                            $('#progress-bar').css('width', progressPercentage + '%');
                        }
                    }
                }
            });
        }, 2000); // Poll every 2 seconds
    }

    // Function to stop progress tracking
    function stopProgressTracking() {
        clearInterval(interval);
    }

    // Start progress tracking immediately if the process is already in progress
    if ($('#cloudflare-image-replace-button').text() === 'Stop Image Replacement') {
        startProgressTracking();
    }
});
