#!/bin/bash
# =====================================================
# GSB Reservation - Script de demarrage
# Verifie si la BDD est initialisee, sinon lance init.php
# =====================================================

echo "=== GSB Reservation - Demarrage ==="

# Attendre que MySQL soit disponible (max 30 secondes)
echo "Verification de la connexion MySQL..."
MAX_RETRIES=30
RETRY=0
while [ $RETRY -lt $MAX_RETRIES ]; do
    php -r "
        \$host = getenv('DB_HOST') ?: 'localhost';
        \$user = getenv('DB_USER') ?: 'root';
        \$pass = getenv('DB_PASS') ?: '';
        try {
            new PDO(\"mysql:host=\$host\", \$user, \$pass);
            echo 'OK';
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null
    if [ $? -eq 0 ]; then
        echo "MySQL est disponible."
        break
    fi
    RETRY=$((RETRY + 1))
    echo "MySQL non disponible, tentative $RETRY/$MAX_RETRIES..."
    sleep 1
done

if [ $RETRY -eq $MAX_RETRIES ]; then
    echo "ERREUR: Impossible de se connecter a MySQL apres $MAX_RETRIES tentatives."
    echo "Demarrage du serveur quand meme (la BDD sera peut-etre disponible plus tard)..."
fi

# Verifier si la BDD est deja initialisee (table Utilisateur existe)
echo "Verification de l'etat de la base de donnees..."
DB_INITIALIZED=$(php -r "
    \$host = getenv('DB_HOST') ?: 'localhost';
    \$name = getenv('DB_NAME') ?: 'GSB_Reservation';
    \$user = getenv('DB_USER') ?: 'root';
    \$pass = getenv('DB_PASS') ?: '';
    try {
        \$pdo = new PDO(\"mysql:host=\$host;dbname=\$name\", \$user, \$pass);
        \$result = \$pdo->query(\"SHOW TABLES LIKE 'Utilisateur'\");
        echo \$result->rowCount() > 0 ? 'yes' : 'no';
    } catch (Exception \$e) {
        echo 'no';
    }
" 2>/dev/null)

if [ "$DB_INITIALIZED" = "yes" ]; then
    echo "Base de donnees deja initialisee. Passage au demarrage du serveur."
else
    echo "Base de donnees non initialisee. Lancement de init.php..."
    php /app/init.php
    if [ $? -eq 0 ]; then
        echo "Initialisation terminee avec succes."
    else
        echo "ATTENTION: Erreur lors de l'initialisation. Verifiez les logs."
    fi
fi

# Demarrer le serveur PHP
echo "=== Demarrage du serveur PHP sur le port ${PORT:-3000} ==="
exec php -S 0.0.0.0:${PORT:-3000} -t /app /app/router.php
