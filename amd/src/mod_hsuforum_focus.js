/**
 * Small function that ties in with bootsrap collapse event classes to scroll a element
 * into view if not on the viewport
 */
 define(['jquery'], function($) {
 
    return {
        init: function() {
            start('box', 2000);

            function start(id, duration) {
              var st = new Date().getTime();
              var interval = setInterval(function() {
                var diff = Math.round(new Date().getTime() - st),
                  val = Math.round(diff / duration * 100);
                val = val > 99 ? 99 : val;
                $("#"+id).css("width", val + "px");
                $("#"+id).text(val + "%");
                if (diff >= duration) {
                  clearInterval(interval);
                }
              }, 100);
            }

            $(document).ready(function(){
                window.currentshowposts = 1;
                $('#showmoreposts').on('click', function(e){
                    e.preventDefault();
                    e.stopPropagation();

                    var currenttext = $(this).find($(".fa"));
                    currenttext.removeClass('fa-comments').addClass('fa-circle-o-notch fa-spin');
                    $('#showmoreposts').addClass('disabled');

                    var tempDiv = $('<div id="tempDiv_'+window.currentshowposts+'"></div>');
                    var url = $(this).attr('href') + '&shownextposts=' + window.currentshowposts;
                    tempDiv.load(url+' .hsuforum-thread-replies',null, M.local_hsuforum_actions.init);
                    $(".hsuforum-thread-replies").append(tempDiv);

                    currenttext.removeClass('fa-circle-o-notch fa-spin').addClass('fa-comments');
                    $('#showmoreposts').removeClass('disabled');
                    window.currentshowposts++;
                    if(window.currentshowposts >= (window.totalposts/window.manyposts)) {
                        $('#showmoreposts').text('All posts loaded');
                        $('#showmoreposts').addClass('disabled');
                    }
                })
            });

            window.addEventListener("load", function () {
                window.setTimeout( function() {
                    $("#pageloadingmodal").hide();
                    $(".modal-backdrop").fadeOut("slow");
                }, 2500);
            });

            window.hascompleted = false;
            var postid = window.location.hash
            if (!window.hascompleted) {
                if (postid) {
                    if ($(postid).closest('li').data('depth') > 0) {
                        $(postid).closest('.posts-collapse-container').addClass('show');
                    }
                    $('html,body').animate({scrollTop: $(postid).offset().top - 53}, 1000);
                }
                window.hascompleted = true;
            }

            $('.posts-collapse-container').on('hide.bs.collapse', function() {
                // Collapsable container id
                let id = $(this)[0].id;
                if (id !== undefined) {
                    let collapseTarget = $(`.collapse-top[data-target="#${id}"]`);
                    let parentPost = $(collapseTarget)[0].parentElement;

                    if (parentPost !== undefined) {
                        let position =  parentPost.getBoundingClientRect();

                        // Only scroll if element is not in viewport
                        if (position.y < 0) {
                            let parentId = `#${$(parentPost)[0].id}`;
                            // Only scroll once collapse event is done
                            $(`#${id}.posts-collapse-container`).on('hidden.bs.collapse', function() {
                                let yAxis = $(parentId).offset().top - 80;
                                // Smoothing out the animation
                                $([document.documentElement, document.body]).animate({
                                    scrollTop: yAxis
                                }, 500);
                            })
                        }
                    }
                }
            });
        }
    };
});
