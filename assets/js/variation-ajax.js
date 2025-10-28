(function($){
    function pickDominant(img, fallback){
        try{
            var c = document.createElement('canvas');
            var w = c.width = Math.min(64, img.naturalWidth || 64);
            var h = c.height = Math.min(64, img.naturalHeight || 64);
            var ctx = c.getContext('2d');
            ctx.drawImage(img,0,0,w,h);
            var d = ctx.getImageData(0,0,w,h).data, r=0,g=0,b=0,cnt=0;
            for(var i=0;i<d.length;i+=4){ r+=d[i]; g+=d[i+1]; b+=d[i+2]; cnt++; }
            r=Math.round(r/cnt); g=Math.round(g/cnt); b=Math.round(b/cnt);
            return 'rgb('+r+','+g+','+b+')';
        }catch(e){ return fallback||'#3b82f6'; }
    }
    function enhance(){ document.querySelectorAll('.qmc-colors .color-card .qmc-thumb img.qmc-mini').forEach(function(img){ var cur=getComputedStyle(img.closest('.color-card')).getPropertyValue('--qmc-outline')||'#3b82f6'; var col=pickDominant(img,cur); img.closest('.color-card').style.setProperty('--qmc-outline',col); }); }
    document.addEventListener('DOMContentLoaded', enhance);

    $(document).on('click','.qmc-lvs a[data-qmc-link]',function(e){
        var $a=$(this), id=parseInt($a.attr('data-product'),10), href=$a.attr('href');
        if(!id){ return; }
        e.preventDefault();
        $.ajax({ url:(window.QMC_LVS?QMC_LVS.rest:'')+'?id='+id, method:'GET', timeout:8000,
            success:function(resp){
                if(!resp||resp.error){ if(href){location.href=href;} else {location.reload();} return; }
                var $t=$('.product_title, h1.product_title, .wd-product-title h1').first();
                if($t.length&&resp.title){ $t.text(resp.title); }
                var $p=$('.summary .price, .entry-summary .price, .wd-single-price .price').first();
                if($p.length&&resp.price){ $p.html(resp.price); }
                var $form=$('form.cart').first();
                if($form.length){
                    $form.attr('action', resp.permalink||$form.attr('action'));
                    $form.find('button[name="add-to-cart"], input[name="add-to-cart"]').val(resp.cart_id);
                    $('#wd-add-to-cart').val(resp.cart_id);
                }
                if(resp.thumb){ var $img=$('.woocommerce-product-gallery__image img, .wp-post-image').first(); if($img.length){ $img.attr('src',resp.thumb).attr('srcset',''); } }
                if(resp.permalink){ history.replaceState({},'',resp.permalink); }
                $('.qmc-lvs').attr('data-current-id',resp.id);
                enhance();
            },
            error:function(){ if(href){location.href=href;} else {location.reload();} }
        });
    });
})(jQuery);
