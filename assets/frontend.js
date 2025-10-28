(function($){
    if (!window.QMC_LVS || !QMC_LVS.enabled) return;

    // Intercept selector clicks — swap product summary without full reload
    $(document).on('click', '.qmc-lvs [data-qmc-link]', function(e){
        var href = $(this).attr('href');
        if (!href || href === '#') return; // disabled

        e.preventDefault();

        var $summary = $(this).closest('.summary, .product-info, .entry-summary');
        if (!$summary.length) $summary = $('.summary');

        var top = window.scrollY || document.documentElement.scrollTop || 0;

        $summary.css('opacity', 0.55);

        fetch(href, {credentials: 'same-origin'})
            .then(function(r){ return r.text(); })
            .then(function(html){
                var doc = (new DOMParser()).parseFromString(html,'text/html');
                var $new = $(doc).find('.summary, .product-info, .entry-summary').first();
                if ($new.length){
                    $summary.replaceWith($new);
                    window.scrollTo({top: top, behavior: 'instant'}); // stay in place
                    history.pushState({}, '', href);
                    $(document.body).trigger('qmc_lvs_replaced');
                } else {
                    window.location.href = href; // fallback
                }
            })
            .catch(function(){
                window.location.href = href;
            });
    });

})(jQuery);
