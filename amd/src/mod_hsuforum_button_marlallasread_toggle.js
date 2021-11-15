define(['jquery'], function ($) {
    function isElementInViewport (element) {
        var rect = element.getBoundingClientRect();

        return (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) && /* or $(window).height() */
            rect.right <= (window.innerWidth || document.documentElement.clientWidth) /* or $(window).width() */
        );
    }
    return {
        init: function () {
            $(document).ready(function (){
                $(window).scroll(function (){
                    let isVisible = isElementInViewport($('#id_filter')[0]);
                    // eslint-disable-next-line no-console
                   if(!isVisible) {
                       $('#markallasread').removeClass('rounded-pill');
                       $('#markallasread').addClass('markallasreadfloatbutton_fixed');
                   }else{
                       $('#markallasread').removeClass('markallasreadfloatbutton_fixed');
                       $('#markallasread').addClass('rounded-pill');
                   }
                });
            });
        }
    };
});
F``