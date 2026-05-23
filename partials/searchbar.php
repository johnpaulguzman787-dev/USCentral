                    <div class="search-container">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="search" placeholder="Search users..." class="form-control">
                        </div>
                    </div>
                    
                        <nav>
                            <ul class="pagination justify-content-end mt-3" id="userPagination"></ul>
                        </nav>
<script>
document.addEventListener("DOMContentLoaded", function () {

    const ROWS_PER_PAGE = 5;

    const table = document.getElementById("userTable");
    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const pagination = document.getElementById("userPagination");
    const searchInput = document.getElementById("search");

    let currentPage = 1;
    let filteredRows = [...rows];

    function renderTable() {
        tbody.innerHTML = "";

        const start = (currentPage - 1) * ROWS_PER_PAGE;
        const end = start + ROWS_PER_PAGE;

        filteredRows.slice(start, end).forEach(row => {
            tbody.appendChild(row);
        });

        renderPagination();
    }

    function renderPagination() {
        pagination.innerHTML = "";

        const pageCount = Math.ceil(filteredRows.length / ROWS_PER_PAGE);
        if (pageCount <= 1) return;

        for (let i = 1; i <= pageCount; i++) {
            const li = document.createElement("li");
            li.className = "page-item " + (i === currentPage ? "active" : "");

            li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
            li.onclick = function (e) {
                e.preventDefault();
                currentPage = i;
                renderTable();
            };

            pagination.appendChild(li);
        }
    }

    // 🔍 REAL-TIME SEARCH (RESTORES DATA WHEN CLEARED)
    searchInput.addEventListener("input", function () {
        const value = this.value.toLowerCase().trim();

        filteredRows = value === ""
            ? [...rows]
            : rows.filter(row =>
                row.textContent.toLowerCase().includes(value)
              );

        currentPage = 1;
        renderTable();
    });

    renderTable();
});
</script>
