// Toggle mobile navigation
const menuToggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('nav ul');

menuToggle.addEventListener('click', () => {
  nav.classList.toggle('active');
});

// Optional: Alert for task add button
document.addEventListener('DOMContentLoaded', () => {
  const addTaskBtn = document.getElementById('addTaskBtn');
  if (addTaskBtn) {
    addTaskBtn.addEventListener('click', () => {
      alert('Task added successfully!');
    });
  }
});

//ALWAYS REMEBER ME POP UP 
document.getElementById("logoutBtn").addEventListener("click", function(e) {
  e.preventDefault(); // Stop link from navigating
  document.getElementById("logoutPopup").classList.remove("hidden");
});

document.getElementById("closePopup").addEventListener("click", function() {
  window.location.href = "login.html"; // Redirect to login
});


funtion generateResponse(){
var text = documentById("text")
car response = document.getElementaryById("respose")

fetch("response.php", {

method: "post",
body: JSON.stringfy({
  text:text.value,
}),
}).then(res=>res.text()).then(res=>)

response.innerHTML = res;

}