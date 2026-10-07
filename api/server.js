require("dotenv").config();

const express = require("express");
const bcrypt = require("bcrypt");
const jwt = require("jsonwebtoken");
const db = require("./db");

const JWT_SECRET = process.env.JWT_SECRET;

const app = express();

app.use(express.json());

app.get("/api/companies/:id/ads", async (req, res) => {
    try {
        const companyId = req.params.id;

        const [companies] = await db.query(
            "SELECT id FROM companies WHERE id = ?",
            [companyId]
        );

        if (companies.length === 0) {
            return res.status(404).json({
                error: "Entreprise introuvable"
            });
        }

        const [rows] = await db.query(`
            SELECT
                advertisements.id,
                advertisements.title,
                advertisements.short_description,
                advertisements.description,
                advertisements.salary,
                advertisements.location,
                advertisements.working_time,
                companies.name AS company,
                categories.name AS category
            FROM advertisements
            JOIN companies
                ON advertisements.company_id = companies.id
            JOIN categories
                ON advertisements.category_id = categories.id
            WHERE advertisements.company_id = ?
        `, [companyId]);

        res.status(200).json(rows);

    } catch (error) {
        console.error(error);
        res.status(500).json({
            error: "Erreur serveur"
        });
    }
});


app.get("/api/ads/:id", async (req, res) => {
    try {
        const adId = req.params.id;

        const [rows] = await db.query(`
            SELECT
                advertisements.id,
                advertisements.title,
                advertisements.short_description,
                advertisements.description,
                advertisements.salary,
                advertisements.location,
                advertisements.working_time,
                companies.name AS company,
                categories.name AS category
            FROM advertisements
            JOIN companies
                ON advertisements.company_id = companies.id
            JOIN categories
                ON advertisements.category_id = categories.id
            WHERE advertisements.id = ?
        `, [adId]);

        if (rows.length === 0) {
            return res.status(404).json({
                error: "Annonce introuvable"
            });
        }

        res.status(200).json(rows[0]);

    } catch (error) {
        console.error(error);
        res.status(500).json({
            error: "Erreur serveur"
        });
    }
});

function authenticateToken(req, res, next) {
    const authHeader = req.headers.authorization;

    if (!authHeader) {
        return res.status(401).json({
            error: "Token manquant"
        });
    }

    const token = authHeader.split(" ")[1];

    try {
        const decoded = jwt.verify(token, JWT_SECRET);

        req.user = decoded;

        next();
    } catch (error) {
        return res.status(401).json({
            error: "Token invalide"
        });
    }
}

app.post("/api/ads", authenticateToken, async (req, res) => {
    try {
        const {
            title,
            short_description,
            description,
            salary,
            location,
            working_time,
            company_id,
            category_id
        } = req.body;

        // Utilisateur actuellement connecté
        const currentUserId = req.user.userId;

        // Vérifier les champs obligatoires
        if (
            !title ||
            !short_description ||
            !description ||
            !salary ||
            !location ||
            !working_time ||
            !company_id ||
            !category_id
        ) {
            return res.status(400).json({
                error: "Champs obligatoires manquants"
            });
        }

        // Vérifier que l'entreprise existe
        const [companies] = await db.query(
            "SELECT id FROM companies WHERE id = ?",
            [company_id]
        );

        if (companies.length === 0) {
            return res.status(404).json({
                error: "Entreprise introuvable"
            });
        }

        // Vérifier que la catégorie existe
        const [categories] = await db.query(
            "SELECT id FROM categories WHERE id = ?",
            [category_id]
        );

        if (categories.length === 0) {
            return res.status(404).json({
                error: "Catégorie introuvable"
            });
        }

        // Vérifier que l'utilisateur est recruteur de l'entreprise
        const [memberships] = await db.query(
            `SELECT *
             FROM company_members
             WHERE person_id = ?
             AND company_id = ?
             AND role = 'recruiter'`,
            [currentUserId, company_id]
        );

        if (memberships.length === 0) {
            return res.status(403).json({
                error: "Vous n'êtes pas autorisé à publier pour cette entreprise"
            });
        }

        // Créer l'annonce
        const [result] = await db.query(`
            INSERT INTO advertisements
            (
                title,
                short_description,
                description,
                salary,
                location,
                working_time,
                company_id,
                category_id,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        `, [
            title,
            short_description,
            description,
            salary,
            location,
            working_time,
            company_id,
            category_id,
            currentUserId
        ]);

        res.status(201).json({
            id: result.insertId,
            message: "Annonce créée"
        });

    } catch (error) {
        console.error(error);

        res.status(500).json({
            error: "Erreur serveur"
        });
    }
});

app.post("/api/login", async (req, res) => {
    try {
        const { email, password } = req.body;

        if (!email || !password) {
            return res.status(400).json({
                error: "Email et mot de passe obligatoires"
            });
        }

        const [people] = await db.query(
            "SELECT id, email, password, role FROM people WHERE email = ?",
            [email]
        );

        if (people.length === 0) {
            return res.status(401).json({
                error: "Email ou mot de passe incorrect"
            });
        }

        const user = people[0];

        const passwordIsCorrect = await bcrypt.compare(
            password,
            user.password
        );

        if (!passwordIsCorrect) {
            return res.status(401).json({
                error: "Email ou mot de passe incorrect"
            });
        }

        const token = jwt.sign(
            {
                userId: user.id,
                role: user.role
            },
            JWT_SECRET,
            {
                expiresIn: "1h"
            }
        );

        res.status(200).json({
            message: "Connexion réussie",
            token: token
        });

    } catch (error) {
        console.error(error);

        res.status(500).json({
            error: "Erreur serveur"
        });
    }
});

app.listen(3000, () => {
    console.log("API démarrée sur http://localhost:3000");
});