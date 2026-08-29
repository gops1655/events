function openModal(id) {
  document.getElementById(id)?.classList.add("open");
}
function closeModal(id) {
  document.getElementById(id)?.classList.remove("open");
}
document.addEventListener("click", (e) => {
  const bg = e.target.closest(".modal-bg");
  if (bg && e.target === bg) bg.classList.remove("open");
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    document.querySelectorAll(".modal-bg.open").forEach((m) => m.classList.remove("open"));
  }
});
function confirmDelete(form, message) {
  if (confirm(message || "Delete this record?")) form.submit();
}
