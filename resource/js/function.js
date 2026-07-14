$(document).ready(function(){ 

    $(".btn_delete").click(function(e){ 

     e.preventDefault(); 
     var deleteId = 2;//unique id of the raw to be deleted 

     var request = $.ajax({ 
     url: "ajax.php", 
     type: "POST", 
     data: { id : deleteId }, 
     dataType: "json" 
     }); 
     request.done(function(msg) { 

      if(msg.status) 
       alert("成功刪除!"); 
      else 
       alert("有出錯喔!!"); 
     }); 
     request.fail(function(jqXHR, textStatus) { 
      alert("Request failed: " + textStatus); 


     }); 

    }); 


}); 