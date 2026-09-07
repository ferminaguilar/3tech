((Drupal, once, $) => {
    // Polyfill jQuery.type for modern jQuery in Drupal 11
    if (typeof $.type !== 'function') {
        $.type = function (obj) {
            if (obj == null) {
                return String(obj);
            }
            return typeof obj === 'object' || typeof obj === 'function'
                ? Object.prototype.toString.call(obj).slice(8, -1).toLowerCase()
                : typeof obj;
        };
    }

    Drupal.behaviors.governmentHeroSlider = {
        attach(context) {
            const sliders = once('hero-slider-init', '.government-hero-slider', context);

            sliders.forEach((sliderElement) => {
                const $slider = $(sliderElement);

                if (typeof $slider.slick === 'function' && !$slider.hasClass('slick-initialized')) {
                    $slider.slick({
                        dots: false,
                        arrows: true,
                        infinite: true,
                        slidesToShow: 1,
                        slidesToScroll: 1,
                        autoplay: true,
                        autoplaySpeed: 5500,
                        pauseOnHover: true,
                        adaptiveHeight: false,
                        speed: 600,
                        prevArrow: '<button type="button" class="slick-prev" aria-label="Previous Slide">Previous</button>',
                        nextArrow: '<button type="button" class="slick-next" aria-label="Next Slide">Next</button>'
                    });
                }
            });
        }
    };
})(Drupal, once, jQuery);