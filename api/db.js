const mysql = require("mysql2/promise");

const db = mysql.createPool({
    host: "localhost",
    user: "root",
    password: "[REDACTED]",
    database: "alternatech"
});

module.exports = db;