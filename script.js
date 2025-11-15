jQuery(document).ready(function($){
    function getPhoneSaved() {
        var p = localStorage.getItem('phone_capture');
        if (p) return p;
        var name = 'phone_capture=';
        var ca = document.cookie.split(';');
        for(var i=0;i<ca.length;i++) {
            var c = ca[i].trim();
            if (c.indexOf(name)===0) return c.substring(name.length,c.length);
        }
        return null;
    }

    function setPhoneSaved(val) {
        localStorage.setItem('phone_capture', val);
        var d = new Date();
        d.setFullYear(d.getFullYear()+1);
        document.cookie = 'phone_capture=' + val + '; path=/; expires=' + d.toUTCString();
    }

    function maskPhone(v) {
        var digits = v.replace(/\D/g,'').slice(0,11);
        if (digits.length <= 2) return digits;
        if (digits.length <= 6) return '(' + digits.slice(0,2) + ') ' + digits.slice(2);
        if (digits.length <= 10) return '(' + digits.slice(0,2) + ') ' + digits.slice(2,6) + '-' + digits.slice(6);
        return '(' + digits.slice(0,2) + ') ' + digits.slice(2,7) + '-' + digits.slice(7);
    }

    var saved = getPhoneSaved();
    if (!saved) {
        $('#pcm-overlay').removeClass('pcm-hidden');
    }

    $(document).on('click','#pcm-close', function(e){
        e.preventDefault();
        $('#pcm-overlay').addClass('pcm-hidden');
    });

    $(document).on('input', '#pcm-phone', function(){
        var val = $(this).val();
        $(this).val(maskPhone(val));
    });

    $(document).on('submit', '#pcm-form', function(e){
        e.preventDefault();
        var phone = $('#pcm-phone').val();
        var digits = phone.replace(/\D/g,'');
        if (digits.length < 8) {
            alert('Por favor, insira um número válido.');
            return;
        }
        setPhoneSaved(digits);

        // create and submit small form with nonce so PHP records cookie and (optionally) view
        var form = $('<form method="POST"></form>');
        form.append($('<input type="hidden" name="phone_capture" />').val(phone));
        form.append($('<input type="hidden" name="pcm_nonce" />').val($('input[name="pcm_nonce"]').val()));
        $('body').append(form);
        form.submit();
    });
});
