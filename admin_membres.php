<?php
session_start();

require_once __DIR__ . '/dataBase/bd.php';
require_once __DIR__ . '/securite/securisation.php';

if (!isset($_SESSION['number']) || !isset($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

function isAdminMembre($connexion, $num) {
    $query = $connexion->prepare("SELECT role FROM membre WHERE num = ? LIMIT 1");
    $query->execute([$num]);
    $result = $query->fetch(PDO::FETCH_ASSOC);
    return $result && $result['role'] === 'admin_membre';
}

function getAllMembers($connexion) {
    $query = $connexion->query(
        "SELECT num, prenom, nom, genre, profession, profession_details, niveau_etude, classe, role, date_inscription 
         FROM membre 
         WHERE confirm = 1 
         ORDER BY date_inscription DESC"
    );
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function deleteMember($connexion, $num) {
    $query = $connexion->prepare("DELETE FROM membre WHERE num = ?");
    return $query->execute([$num]);
}

function addMember($connexion, $data) {
    $checkQuery = $connexion->prepare("SELECT num FROM membre WHERE num = ? OR email = ? LIMIT 1");
    $checkQuery->execute([$data['num'], $data['email']]);

    if ($checkQuery->fetch()) {
        return ['error' => 'numero_ou_email_existe'];
    }

    $hashedPassword = password_hash($data['passeword'], PASSWORD_DEFAULT);

    $professionDetails = null;
    $niveauEtude = null;
    $classe = null;
    $filiere = null;

    if ($data['profession'] === 'travailleur') {
        $professionDetails = $data['profession_details'];
    } elseif ($data['profession'] === 'etudiant') {
        $niveauEtude = $data['niveau_etude'];
        $filiere = $data['filiere'];
    } elseif ($data['profession'] === 'eleve') {
        $classe = $data['classe'];
    }

    $insertQuery = $connexion->prepare(
        "INSERT INTO membre (num, prenom, nom, email, date_naissance, genre, profession, profession_details, niveau_etude, filiere, classe, passeword, confirm, date_inscription)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())"
    );

    $insertQuery->execute([
        $data['num'],
        $data['prenom'],
        $data['nom'],
        $data['email'],
        $data['date_naissance'],
        $data['genre'],
        $data['profession'],
        $professionDetails,
        $niveauEtude,
        $filiere,
        $classe,
        $hashedPassword
    ]);

    return true;
}

$error = '';
$success = '';

try {
    $connexion = getDatabaseConnection();
    
    if (!isAdminMembre($connexion, $_SESSION['number'])) {
        header('Location: accueil.php');
        exit;
    }
    
    $members = getAllMembers($connexion);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            $memberNum = securisation($_POST['member_num'] ?? '');
            
            if ($memberNum === $_SESSION['number']) {
                $error = "Vous ne pouvez pas vous supprimer vous-même";
            } elseif (empty($memberNum)) {
                $error = "Membre invalide";
            } else {
                if (deleteMember($connexion, $memberNum)) {
                    $success = "Membre supprimé avec succès";
                    $members = getAllMembers($connexion);
                } else {
                    $error = "Erreur lors de la suppression";
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'add') {
            $num = securisation($_POST['num'] ?? '');
            $prenom = securisation($_POST['prenom'] ?? '');
            $nom = securisation($_POST['nom'] ?? '');
            $email = securisation($_POST['email'] ?? '');
            $date_naissance = securisation($_POST['date_naissance'] ?? '');
            $genre = securisation($_POST['genre'] ?? '');
            $profession = securisation($_POST['profession'] ?? '');
            $profession_details = securisation($_POST['profession_details'] ?? '');
            $niveau_etude = securisation($_POST['niveau_etude'] ?? '');
            $filiere = securisation($_POST['filiere'] ?? '');
            $classe = securisation($_POST['classe'] ?? '');
            $passeword = securisation($_POST['passeword'] ?? '');

            if (empty($num) || empty($prenom) || empty($nom) || empty($email) || empty($date_naissance) || empty($genre) || empty($profession) || empty($passeword)) {
                $error = "Veuillez remplir tous les champs obligatoires";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Veuillez saisir une adresse e-mail valide";
            } elseif ($profession === 'travailleur' && empty($profession_details)) {
                $error = "Veuillez indiquer la profession";
            } elseif ($profession === 'etudiant' && (empty($niveau_etude) || empty($filiere))) {
                $error = "Veuillez indiquer le niveau d'études et la filière";
            } elseif ($profession === 'eleve' && empty($classe)) {
                $error = "Veuillez indiquer la classe";
            } else {
                $userData = [
                    'num' => $num,
                    'prenom' => $prenom,
                    'nom' => $nom,
                    'email' => $email,
                    'date_naissance' => $date_naissance,
                    'genre' => $genre,
                    'profession' => $profession,
                    'profession_details' => $profession_details,
                    'niveau_etude' => $niveau_etude,
                    'filiere' => $filiere,
                    'classe' => $classe,
                    'passeword' => $passeword
                ];
                
                $result = addMember($connexion, $userData);
                
                if ($result === true) {
                    $success = "Membre ajouté avec succès";
                    $members = getAllMembers($connexion);
                } elseif (is_array($result) && isset($result['error'])) {
                    if ($result['error'] === 'numero_existe') {
                        $error = "Ce numéro est déjà utilisé";
                    }
                }
            }
        }
    }
    
} catch (Throwable $e) {
    error_log("Erreur DB: " . $e->getMessage());
    $error = "Une erreur est survenue";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Membres - DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</title>
    <link rel="stylesheet" href="assets/css/daara-theme.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #F2F2F2;
            min-height: 100vh;
            padding: 20px;
        }
        
        .bg-animation {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }
        
        .circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(201, 162, 77, 0.08);
            animation: float 25s infinite ease-in-out;
        }
        
        .circle:nth-child(1) { width: 400px; height: 400px; top: -200px; right: -200px; }
        .circle:nth-child(2) { width: 300px; height: 300px; bottom: -150px; left: -150px; animation-delay: 3s; }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.3; }
            50% { transform: translateY(-40px) rotate(180deg); opacity: 0.6; }
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: #FFFFFF;
            border: 1px solid #D0D0D0;
            border-radius: 25px;
            box-shadow: 0 20px 60px rgba(17,17,17,0.08);
            overflow: hidden;
            position: relative;
            z-index: 1;
            animation: slideUp 0.8s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #B58B45, #d6b57f, #B58B45);
            background-size: 200% 100%;
            animation: gradient-shift 3s ease infinite;
        }
        
        @keyframes gradient-shift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .header {
            background: linear-gradient(135deg, #111111 0%, #1d1d1d 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(201, 162, 77, 0.3);
            color: white;
            border: 2px solid rgba(225, 199, 122, 0.5);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: rgba(201, 162, 77, 0.5);
            transform: translateY(-2px);
        }
        
        .content { padding: 30px; }
        
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .alert-error { background: #ffe6e6; color: #d32f2f; border-left: 4px solid #d32f2f; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #d6b57f;
        }
        
        .tab {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            color: #111111;
            border-bottom-color: #B58B45;
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }
        
        .tab-content.active { display: block; }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .form-section {
            background: #F2F2F2;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        
        .form-section h3 { color: #111111; margin-bottom: 20px; }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group { margin-bottom: 15px; }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #111111;
            font-weight: 600;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #d6b57f;
            border-radius: 10px;
            font-size: 15px;
            background: #F2F2F2;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #B58B45;
            background: #FFFFFF;
            box-shadow: 0 3px 10px rgba(201, 162, 77, 0.2);
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #111111, #1d1d1d);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(31, 94, 67, 0.3);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        thead {
            background: linear-gradient(135deg, #111111, #1d1d1d);
            color: white;
        }
        
        th, td { padding: 15px; text-align: left; }
        
        tbody tr {
            border-bottom: 1px solid #d6b57f;
            transition: all 0.3s ease;
        }
        
        tbody tr:hover {
            background: #F2F2F2;
            transform: scale(1.01);
        }
        
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: bold;
            background: #B58B45;
            color: white;
        }
        
        .delete-btn {
            background: #f44336;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        
        .delete-btn:hover {
            background: #d32f2f;
            transform: translateY(-2px);
        }
        
        /* Footer */
        .footer {
            background: linear-gradient(135deg, #111111 0%, #1d1d1d 100%);
            color: white;
            padding: 20px;
            text-align: center;
            margin-top: 30px;
            border-top: 4px solid #B58B45;
        }
        
        .footer-content {
            font-size: 14px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="bg-animation">
        <div class="circle"></div>
        <div class="circle"></div>
    </div>
    
    <div class="container">
        <div class="header">
            <a href="accueil.php" class="back-btn">← Retour</a>
            <h1>👥 Gestion des Membres</h1>
            <p>Ajouter et supprimer des membres</p>
        </div>
        
        <div class="content">
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('add')">➕ Ajouter un membre</button>
                <button class="tab" onclick="switchTab('list')">📋 Liste des membres (<?php echo count($members); ?>)</button>
            </div>
            
            <!-- Formulaire d'ajout -->
            <div id="add" class="tab-content active">
                <div class="form-section">
                    <h3>Ajouter un nouveau membre</h3>
                    <form method="POST" id="addForm">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="form-group">
                            <label for="num">Numéro de téléphone *</label>
                            <input type="tel" name="num" id="num" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="prenom">Prénom *</label>
                                <input type="text" name="prenom" id="prenom" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="nom">Nom *</label>
                                <input type="text" name="nom" id="nom" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="genre">Genre *</label>
                                <select name="genre" id="genre" required>
                                    <option value="">Sélectionner</option>
                                    <option value="Homme">Homme</option>
                                    <option value="Femme">Femme</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="profession">Profession *</label>
                                <select name="profession" id="profession" required onchange="showProfessionFields()">
                                    <option value="">Sélectionner</option>
                                    <option value="travailleur">Travailleur</option>
                                    <option value="etudiant">Étudiant</option>
                                    <option value="eleve">Élève</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group" id="profession_details_field" style="display: none;">
                            <label for="profession_details">Profession *</label>
                            <input type="text" name="profession_details" id="profession_details" placeholder="Ex: Enseignant, Commerçant">
                        </div>
                        
                        <div class="form-group" id="niveau_etude_field" style="display: none;">
                            <label for="niveau_etude">Niveau d'études *</label>
                            <select name="niveau_etude" id="niveau_etude">
                                <option value="">Sélectionner</option>
                                <option value="Licence 1">Licence 1</option>
                                <option value="Licence 2">Licence 2</option>
                                <option value="Licence 3">Licence 3</option>
                                <option value="Master 1">Master 1</option>
                                <option value="Master 2">Master 2</option>
                                <option value="Doctorat">Doctorat</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="classe_field" style="display: none;">
                            <label for="classe">Classe *</label>
                            <input type="text" name="classe" id="classe" placeholder="Ex: 6ème, Terminale S">
                        </div>
                        
                        <div class="form-group">
                            <label for="passeword">Mot de passe *</label>
                            <input type="password" name="passeword" id="passeword" required minlength="6" placeholder="Minimum 6 caractères">
                        </div>
                        
                        <button type="submit" class="submit-btn">➕ Ajouter le membre</button>
                    </form>
                </div>
            </div>
            
            <!-- Liste des membres -->
            <div id="list" class="tab-content">
                <table>
                    <thead>
                        <tr>
                            <th>Nom complet</th>
                            <th>Numéro</th>
                            <th>Genre</th>
                            <th>Profession</th>
                            <th>Détails</th>
                            <th>Rôle</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($member['prenom'] . ' ' . $member['nom']); ?></td>
                                <td><?php echo htmlspecialchars($member['num']); ?></td>
                                <td><?php echo htmlspecialchars($member['genre']); ?></td>
                                <td><span class="badge"><?php echo htmlspecialchars(ucfirst($member['profession'])); ?></span></td>
                                <td>
                                    <?php 
                                    if ($member['profession'] === 'travailleur') echo htmlspecialchars($member['profession_details'] ?? '-');
                                    if ($member['profession'] === 'etudiant') echo htmlspecialchars($member['niveau_etude'] ?? '-');
                                    if ($member['profession'] === 'eleve') echo htmlspecialchars($member['classe'] ?? '-');
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($member['role']); ?></td>
                                <td>
                                    <?php if ($member['num'] !== $_SESSION['number']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Confirmer la suppression ?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="member_num" value="<?php echo htmlspecialchars($member['num']); ?>">
                                            <button type="submit" class="delete-btn">🗑️ Supprimer</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #999;">Vous</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Footer -->
    <div class="footer">
         Conçu par BICHRY_DEV © <?php echo date('Y'); ?>
    </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            const tabs = document.querySelectorAll('.tab');
            const contents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => tab.classList.remove('active'));
            contents.forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }
        
        function showProfessionFields() {
            const profession = document.getElementById('profession').value;
            const professionDetailsField = document.getElementById('profession_details_field');
            const niveauEtudeField = document.getElementById('niveau_etude_field');
            const classeField = document.getElementById('classe_field');
            
            professionDetailsField.style.display = 'none';
            niveauEtudeField.style.display = 'none';
            classeField.style.display = 'none';
            
            document.getElementById('profession_details').removeAttribute('required');
            document.getElementById('niveau_etude').removeAttribute('required');
            document.getElementById('classe').removeAttribute('required');
            
            if (profession === 'travailleur') {
                professionDetailsField.style.display = 'block';
                document.getElementById('profession_details').setAttribute('required', 'required');
            } else if (profession === 'etudiant') {
                niveauEtudeField.style.display = 'block';
                document.getElementById('niveau_etude').setAttribute('required', 'required');
            } else if (profession === 'eleve') {
                classeField.style.display = 'block';
                document.getElementById('classe').setAttribute('required', 'required');
            }
        }
        
        // Animation des lignes du tableau
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                setTimeout(() => {
                    row.style.opacity = '1';
                    row.style.animation = `slideIn 0.5s ease-out ${index * 0.05}s both`;
                }, 100);
            });
        });
    </script>
</body>
</html>