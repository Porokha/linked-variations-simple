(function($){
    function replaceHTML(res){
        try {
            if(res.permalink){ history.pushState({}, '', res.permalink); }
            var $title = $('.product_title, .entry-title.product_title').first();
            if($title.length && res.title_html){ $title.replaceWith(res.title_html); }
            var $price = $('.summary .price').first();
            if($price.length && res.price_html){ $price.html(res.price_html); }
            var $gallery = $('.woocommerce-product-gallery').first();
            if(!$gallery.length){ $gallery = $('.images').first(); }
            if($gallery.length && res.gallery_html){ $gallery.html(res.gallery_html); }
            var $summary = $('.summary').first();
            var $form = $summary.find('form.cart').first();
            if($form.length && res.cart_html){ $form.replaceWith(res.cart_html); }
            else if($summary.length && res.cart_html){ $summary.append(res.cart_html); }
            if(res.selectors_html){
                var $old = $('.qmc-lvs').first();
                if($old.length){ $old.replaceWith(res.selectors_html); }
            }
        } catch(err){ console.error('[QMC-LVS] replace error', err); }
    }
    $(document).on('click','a[data-qmc-link="1"]',function(e){
        var pid = parseInt($(this).attr('data-product'),10);
        if(!pid){ return; }
        e.preventDefault();
        var $wrap = $('.qmc-lvs').first(); $wrap.css('opacity', .6);
        $.get(QMC_LVS.rest, { id: pid }).done(function(res){ replaceHTML(res); })
            .always(function(){ $wrap.css('opacity', 1); });
    });
})(jQuery);
