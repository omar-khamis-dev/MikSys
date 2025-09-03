let selectedElement = null;

// بيانات وهمية لكل عنصر
function getFakeData(type){
    const randomNum = Math.floor(Math.random()*10000);
    switch(type){
        case 'username': return 'user'+randomNum;
        case 'password': return 'pass'+randomNum;
        case 'id': return 'ID'+randomNum;
        case 'expiry': 
            let d = new Date();
            d.setDate(d.getDate() + Math.floor(Math.random()*30));
            return d.toLocaleDateString('ar-EG');
        default: return type;
    }
}

function addElement(type){
    let el = document.createElement('div');
    el.className = 'card-element';
    el.innerText = getFakeData(type); // المعاينة مباشرة
    el.dataset.type = type;
    el.style.fontSize = '14px';
    el.style.color = '#000';
    el.style.fontFamily = 'Arial';

    // تحديد العنصر عند الضغط عليه
    el.onclick = function(e){
        e.stopPropagation();
        selectElement(el);
    };

    // السحب والإفلات داخل الكرت
    el.onmousedown = function(e){
        let offsetX = e.offsetX;
        let offsetY = e.offsetY;

        function mouseMoveHandler(e){
            el.style.left = (e.clientX - offsetX - el.parentElement.getBoundingClientRect().left) + 'px';
            el.style.top = (e.clientY - offsetY - el.parentElement.getBoundingClientRect().top) + 'px';
        }

        function mouseUpHandler(){
            document.removeEventListener('mousemove', mouseMoveHandler);
            document.removeEventListener('mouseup', mouseUpHandler);
        }

        document.addEventListener('mousemove', mouseMoveHandler);
        document.addEventListener('mouseup', mouseUpHandler);
    };

    document.getElementById('card-preview').appendChild(el);
    selectElement(el);
}

// اختيار العنصر الحالي
function selectElement(el){
    selectedElement = el;
    document.getElementById('font-family').value = el.style.fontFamily;
    document.getElementById('font-size').value = parseInt(el.style.fontSize);
    document.getElementById('font-color').value = rgb2hex(el.style.color);
}

// تحديث خصائص العنصر المحدد
function updateSelected(prop, value){
    if(selectedElement){
        selectedElement.style[prop] = value;
    }
}

// حذف العنصر
function deleteSelected(){
    if(selectedElement){
        selectedElement.remove();
        selectedElement = null;
    }
}

// تغيير الخلفية
function changeBackground(){
    let url = prompt("أدخل رابط الخلفية:");
    if(url){
        document.getElementById('card-preview').style.backgroundImage = `url(${url})`;
    }
}

// حفظ القالب
function saveTemplate(){
    let html = document.getElementById('card-preview').innerHTML;
    let name = prompt("أدخل اسم القالب:");
    if(name){
        fetch('save_template.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({name: name, html: html})
        })
        .then(res => res.json())
        .then(data => alert("تم حفظ القالب بنجاح!"))
    }
}

// تحويل rgb إلى hex
function rgb2hex(rgb){
    if(!rgb) return '#000000';
    let result = /^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/.exec(rgb);
    return result ? "#" + 
        ("0" + parseInt(result[1],10).toString(16)).slice(-2) +
        ("0" + parseInt(result[2],10).toString(16)).slice(-2) +
        ("0" + parseInt(result[3],10).toString(16)).slice(-2) : rgb;
}

// إلغاء تحديد العنصر عند الضغط خارج الكرت
document.getElementById('card-preview').onclick = function(){ selectedElement = null; };
