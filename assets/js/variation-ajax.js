(function($){
    $(document).on('click','a[data-qmc-link="1"]',function(e){
        var pid = parseInt($(this).attr('data-product'),10);
        if(!pid){ return; }
        e.preventDefault();
        var $wrap = $('.qmc-lvs').first();
        $wrap.css('opacity', .6);
        $.get(QMC_LVS.rest, { id: pid }).done(function(res){
            try {
                if(res.permalink){ history.pushState({}, '', res.permalink); }
                if(res.title_html){ $('.product_title').first().replaceWith(res.title_html); }
                if(res.price_html){ $('.summary .price').first().html(res.price_html); }
                if(res.gallery_html){ $('.woocommerce-product-gallery, .images').first().html(res.gallery_html); }
                if(res.cart_html){ var $form = $('.summary form.cart').first(); if($form.length){ $form.replaceWith(res.cart_html); } else { $('.summary').first().append(res.cart_html); } }
                // refresh selectors by reloading fragment
                $.get(res.permalink, { fragment: 'qmc_lvs' }).done(function(html){
                    var tmp = $('<div>').html(html);
                    var frag = tmp.find('.qmc-lvs').first();
                    if(frag.length){ $('.qmc-lvs').first().replaceWith(frag); }
                });
            } catch(err){ console.error(err); }
        }).always(function(){ $wrap.css('opacity', 1); });
    });
})(jQuery);