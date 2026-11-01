(function () {
  var menuButton = document.getElementById("menu-button");
  var mobileMenu = document.getElementById("mobile-menu");

  if (!menuButton || !mobileMenu) {
    return;
  }

  function setMenuOpen(isOpen) {
    menuButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
    mobileMenu.classList.toggle("hidden", !isOpen);
  }

  menuButton.addEventListener("click", function () {
    var isOpen = menuButton.getAttribute("aria-expanded") === "true";
    setMenuOpen(!isOpen);
  });

  mobileMenu.querySelectorAll("a").forEach(function (link) {
    link.addEventListener("click", function () {
      setMenuOpen(false);
    });
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      setMenuOpen(false);
    }
  });
})();
