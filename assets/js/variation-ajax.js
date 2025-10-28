(function($){
    function pickDominant(img, fallback){
        try{
            var c = document.createElement('canvas');
            var w = c.width = Math.min(64, img.naturalWidth || 64);
            var h = c.height = Math.min(64, img.naturalHeight || 64);
            var ctx = c.getContext('2d');
            ctx.drawImage(img,0,0,w,h);
            var data = ctx.getImageData(0,0,w,h).data;
            var r=0,g=0,b=0,count=0;
            for(var i=0;i<data.length;i+=4){
                r+=data[i]; g+=data[i+1]; b+=data[i+2]; count++;
            }
            r=Math.round(r/count); g=Math.round(g/count); b=Math.round(b/count);
            return 'rgb('+r+','+g+','+b+')';
        }catch(e){
            return fallback || '#3b82f6';
        }
    }

    function enhanceSwatchOutlines(){
        $('.qmc-colors .color-card .qmc-thumb img.qmc-mini').each(function(){
            var img = this;
            var col = pickDominant(img, getComputedStyle(img.closest('.color-card')).getPropertyValue('--qmc-outline') || '#3b82f6');
            img.closest('.color-card').style.setProperty('--qmc-outline', col);
        });
    }
    document.addEventListener('DOMContentLoaded', enhanceSwatchOutlines);

    $(document).on('click','.qmc-lvs a[data-qmc-link]',function(e){
        var $link = $(this);
        var id = parseInt($link.attr('data-product'),10);
        if(!id){ return; }
        e.preventDefault();
        $.ajax({
            url: (window.QMC_LVS ? QMC_LVS.rest : '') + '?id=' + id,
            method:'GET',
            timeout: 8000,
            success: function(resp){
                try{
                    if(!resp || resp.error){ throw new Error('resp'); }
                    var $t = $('.product_title, h1.product_title, .wd-product-title h1').first();
                    if($t.length && resp.title){ $t.text(resp.title); }
                    var $p = $('.summary .price, .entry-summary .price, .wd-single-price .price').first();
                    if($p.length && resp.price){ $p.html(resp.price); }
                    var $form = $('form.cart').first();
                    if($form.length){
                        $form.attr('action', resp.permalink || $form.attr('action'));
                        $form.find('button[name="add-to-cart"], input[name="add-to-cart"]').val(resp.cart_id);
                        $('#wd-add-to-cart').val(resp.cart_id);
                    }
                    if(resp.permalink){ window.history.replaceState({}, '', resp.permalink); }
                    $('.qmc-lvs').attr('data-current-id', resp.id);
                    enhanceSwatchOutlines();
                } catch(err){
                    if(resp && resp.permalink){ window.location.href = resp.permalink; }
                    else { window.location.reload(); }
                }
            },
            error: function(){
                var url = $link.attr('href');
                if(url){ window.location.href = url; } else { window.location.reload(); }
            }
        });
    });
})(jQuery);
