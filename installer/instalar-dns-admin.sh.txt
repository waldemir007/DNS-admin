#!/bin/bash
###############################################################################
#  DNS Admin v1.0.3 — Instalador Automático                                   #
#  • Instala MariaDB + Apache + PHP + Bind9 + phpMyAdmin                     #
#  • Baixa os arquivos do painel do GitHub                                    #
#  • Cria database.php automaticamente com a senha                            #
#  • Configura tudo                                                          #
###############################################################################

VERDE='\033[0;32m'
AMARELO='\033[1;33m'
VERMELHO='\033[0;31m'
AZUL='\033[0;34m'
CIANO='\033[0;36m'
CINZA='\033[0;90m'
NC='\033[0m'

log()    { echo -e "${VERDE}[✓]${NC} $1"; }
info()   { echo -e "${AZUL}[i]${NC} $1"; }
warn()   { echo -e "${AMARELO}[!]${NC} $1"; }
erro()   { echo -e "${VERMELHO}[✗]${NC} $1"; }
pular()  { echo -e "${CINZA}[⊘]${NC} $1"; }
titulo() { echo -e "\n${CIANO}═══════════════════════════════════════════════════════════${NC}"; echo -e "${CIANO}  $1${NC}"; echo -e "${CIANO}═══════════════════════════════════════════════════════════${NC}\n"; }

declare -a REL_OK=()
declare -a REL_PULADO=()
declare -a REL_ERRO=()
add_ok()     { REL_OK+=("$1"); }
add_pulado() { REL_PULADO+=("$1"); }
add_erro()   { REL_ERRO+=("$1"); }

pkg_instalado() { dpkg -l "$1" 2>/dev/null | grep -q "^ii"; }
cmd_existe()    { command -v "$1" >/dev/null 2>&1; }

# ============================================================================
# VERIFICAÇÕES INICIAIS
# ============================================================================
[ "$EUID" -ne 0 ] && echo "Execute como root: sudo bash $0" && exit 1

if ! grep -q "Debian GNU/Linux 12" /etc/os-release 2>/dev/null; then
    warn "Este script foi feito para Debian 12."
    read -p "Continuar? (s/n): " resp
    [ "$resp" != "s" ] && erro "Cancelado." && exit 1
fi

clear
echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║                                                          ║"
echo "║           DNS Admin v1.0.3 — Instalador                  ║"
echo "║                                                          ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# ============================================================================
# 0/10 — DEPENDÊNCIAS BÁSICAS
# ============================================================================
titulo "0/10 — Dependências básicas"

export DEBIAN_FRONTEND=noninteractive
apt update -qq 2>/dev/null

for pkg in sudo wget curl unzip ca-certificates gnupg lsb-release; do
    if pkg_instalado "$pkg"; then
        pular "$pkg já instalado"
    else
        info "Instalando $pkg..."
        apt install -y -qq "$pkg" >/dev/null 2>&1
        pkg_instalado "$pkg" && log "$pkg instalado" || erro "Falha: $pkg"
    fi
done

for cmd in sudo wget unzip; do
    if ! cmd_existe "$cmd"; then
        erro "$cmd não disponível — abortando"
        exit 1
    fi
done

add_ok "Dependências básicas"

# ============================================================================
# DETECÇÃO
# ============================================================================
titulo "Detectando instalações existentes"

MARIADB_INSTALADO=0
APACHE_INSTALADO=0
PHP_INSTALADO=0
BIND_INSTALADO=0
PHPMYADMIN_INSTALADO=0

pkg_instalado "mariadb-server" && MARIADB_INSTALADO=1 && log "MariaDB detectado"
pkg_instalado "apache2"        && APACHE_INSTALADO=1  && log "Apache2 detectado"
pkg_instalado "php"            && PHP_INSTALADO=1     && log "PHP detectado"
pkg_instalado "bind9"          && BIND_INSTALADO=1    && log "Bind9 detectado"
pkg_instalado "phpmyadmin"     && PHPMYADMIN_INSTALADO=1 && log "phpMyAdmin detectado"

BANCO_EXISTE=0
if [ $MARIADB_INSTALADO -eq 1 ] && systemctl is-active --quiet mariadb 2>/dev/null; then
    if mysql -u root -e "USE dns;" 2>/dev/null; then
        BANCO_EXISTE=1
        log "Banco 'dns' já existe"
    fi
fi

echo ""

# ============================================================================
# REDES PADRÃO (fixas)
# ============================================================================
REDES_PADRAO="127.0.0.1/32
::1/128
172.16.0.0/12
10.0.0.0/8
192.168.0.0/16"

# ============================================================================
# COLETA DE DADOS
# ============================================================================
titulo "Configurações"

IP_SERVIDOR=$(hostname -I | awk '{print $1}')
info "IP detectado: $IP_SERVIDOR"
echo ""

# ---- Senha do banco ----
while true; do
    read -s -p "Senha do banco de dados (root): " DB_ROOT_PASS
    echo ""
    if [ ${#DB_ROOT_PASS} -lt 8 ]; then
        warn "Mínimo 8 caracteres."
        continue
    fi
    read -s -p "Confirme a senha: " DB_ROOT_PASS_2
    echo ""
    if [ "$DB_ROOT_PASS" != "$DB_ROOT_PASS_2" ]; then
        warn "Senhas não coincidem. Tente novamente."
        continue
    fi
    break
done

echo ""

# ---- Senha do admin ----
while true; do
    read -s -p "Senha do admin do painel: " ADMIN_PASS
    echo ""
    if [ ${#ADMIN_PASS} -lt 6 ]; then
        warn "Mínimo 6 caracteres."
        continue
    fi
    read -s -p "Confirme a senha: " ADMIN_PASS_2
    echo ""
    if [ "$ADMIN_PASS" != "$ADMIN_PASS_2" ]; then
        warn "Senhas não coincidem. Tente novamente."
        continue
    fi
    break
done

# ---- Confirmação ----
echo ""
titulo "Confirmação"
echo "  IP do servidor:   $IP_SERVIDOR"
echo "  Senha do banco:   ${DB_ROOT_PASS:0:3}****${DB_ROOT_PASS: -2}"
echo "  Senha do admin:   ${ADMIN_PASS:0:3}****${ADMIN_PASS: -2}"
echo ""
echo "  Redes permitidas (padrão):"
echo "    • 127.0.0.1/32"
echo "    • ::1/128"
echo "    • 172.16.0.0/12"
echo "    • 10.0.0.0/8"
echo "    • 192.168.0.0/16"
echo ""

while true; do
    read -p "Está tudo correto? (S/n): " confirma
    
    case "$confirma" in
        S|s)
            echo ""
            log "Confirmado. Iniciando instalação..."
            echo ""
            break
            ;;
        N|n)
            echo ""
            erro "Instalação cancelada pelo usuário."
            echo ""
            warn "Para instalar corretamente:"
            echo ""
            echo -e "   ${CIANO}1. Execute o script novamente:${NC}"
            echo -e "      sudo bash $0"
            echo ""
            echo -e "   ${CIANO}2. Informe as credenciais corretas:${NC}"
            echo -e "      ${AMARELO}•${NC} Senha do banco de dados (mínimo 8 caracteres)"
            echo -e "      ${AMARELO}•${NC} Senha do admin do painel (mínimo 6 caracteres)"
            echo ""
            exit 0
            ;;
        *)
            warn "Resposta incorreta. Digite S para Sim ou N para Não."
            ;;
    esac
done

# ============================================================================
# DEFINIÇÕES
# ============================================================================
DB_NAME="dns"
APP_DIR="/var/www/dns-admin"
BIND_ZONES_DIR="/var/cache/bind/zones"
BIND_LOG_DIR="/var/log/named"
ADMIN_USER="admin"
ADMIN_EMAIL="admin@local"

# GitHub
GITHUB_USER="waldemir007"
GITHUB_REPO="DNS-admin"
GITHUB_BRANCH="main"
GITHUB_URL="https://github.com/${GITHUB_USER}/${GITHUB_REPO}/archive/refs/heads/${GITHUB_BRANCH}.zip"

# ============================================================================
# 1/10 — PACOTES BASE
# ============================================================================
titulo "1/10 — Pacotes base"

for pkg in nano vim htop iotop sysstat net-tools cron logrotate rsync; do
    pkg_instalado "$pkg" || apt install -y -qq "$pkg" >/dev/null 2>&1
done

add_ok "Pacotes base"

# ============================================================================
# 2/10 — MARIADB
# ============================================================================
titulo "2/10 — MariaDB"

if [ $MARIADB_INSTALADO -eq 0 ]; then
    apt install -y -qq mariadb-server mariadb-client >/dev/null 2>&1
    add_ok "MariaDB instalado"
else
    pular "MariaDB já instalado"
    add_pulado "MariaDB"
fi

if [ ! -f /etc/mysql/mariadb.conf.d/60-dns-admin.cnf ]; then
    cat > /etc/mysql/mariadb.conf.d/60-dns-admin.cnf <<'MYEOF'
[mysqld]
max_connections         = 500
wait_timeout            = 60
interactive_timeout     = 60
max_allowed_packet      = 64M
innodb_buffer_pool_size = 512M
innodb_log_file_size    = 128M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method     = O_DIRECT
tmp_table_size          = 128M
max_heap_table_size     = 128M
query_cache_type        = 0
query_cache_size        = 0
character-set-server    = utf8mb4
collation-server        = utf8mb4_unicode_ci
MYEOF
    add_ok "Otimizações MariaDB"
else
    add_pulado "Otimizações MariaDB"
fi

systemctl enable mariadb >/dev/null 2>&1
systemctl restart mariadb >/dev/null 2>&1
sleep 3

if mysql -u root -e "SELECT 1;" >/dev/null 2>&1; then
    ROOT_CMD="mysql -u root"
    $ROOT_CMD -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}'; FLUSH PRIVILEGES;" 2>/dev/null
    add_ok "Senha root definida"
    ROOT_CMD="mysql -u root -p${DB_ROOT_PASS}"
elif mysql -u root -p"${DB_ROOT_PASS}" -e "SELECT 1;" >/dev/null 2>&1; then
    ROOT_CMD="mysql -u root -p${DB_ROOT_PASS}"
    add_pulado "Senha root"
else
    erro "Não consegui conectar ao MariaDB"
    add_erro "Conexão MariaDB"
    ROOT_CMD="mysql -u root -p${DB_ROOT_PASS}"
fi

if ! $ROOT_CMD -e "USE ${DB_NAME};" 2>/dev/null; then
    info "Criando banco e tabelas..."
    $ROOT_CMD <<SQLEOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME}
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ${DB_NAME};

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    nivel ENUM('admin','user') DEFAULT 'user',
    ativo TINYINT(1) DEFAULT 1,
    ultimo_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS zonas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL UNIQUE,
    tipo ENUM('master','slave') DEFAULT 'master',
    primario VARCHAR(255) NULL,
    admin_email VARCHAR(255) NOT NULL,
    ttl INT DEFAULT 3600,
    refresh INT DEFAULT 10800,
    retry INT DEFAULT 3600,
    expire INT DEFAULT 604800,
    negative_ttl INT DEFAULT 86400,
    serial INT DEFAULT 1,
    ativo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS registros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zona_id INT NOT NULL,
    nome VARCHAR(255) NOT NULL,
    tipo ENUM('A','AAAA','CNAME','MX','TXT','NS','SRV','PTR','CAA') NOT NULL,
    valor TEXT NOT NULL,
    ttl INT NULL,
    prioridade INT NULL,
    ativo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (zona_id) REFERENCES zonas(id) ON DELETE CASCADE,
    INDEX idx_zona (zona_id),
    INDEX idx_tipo (tipo)
);

CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    acao VARCHAR(100) NOT NULL,
    detalhe TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS config (
    chave VARCHAR(100) PRIMARY KEY,
    valor TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ptr_regras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zona_id INT NOT NULL,
    cidr VARCHAR(45) NOT NULL,
    inicio BIGINT UNSIGNED NOT NULL,
    fim BIGINT UNSIGNED NOT NULL,
    bits INT NOT NULL,
    hostname VARCHAR(255) NOT NULL,
    prefixar_ip TINYINT(1) DEFAULT 0,
    ativo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (zona_id) REFERENCES zonas(id) ON DELETE CASCADE,
    UNIQUE KEY uk_zona_cidr (zona_id, cidr),
    INDEX idx_range (inicio, fim),
    INDEX idx_bits (bits)
);

INSERT IGNORE INTO config (chave, valor) VALUES
('recursao_ativa',       '1'),
('redes_permitidas',     '127.0.0.1/32\n::1/128\n172.16.0.0/12\n10.0.0.0/8\n192.168.0.0/16'),
('forwarders',           ''),
('usar_root_servers',    '1'),
('dnssec_validation',    'auto'),
('max_cache_size',       '512'),
('max_cache_ttl',        '86400'),
('query_log',            '1');
SQLEOF
    add_ok "Banco + 6 tabelas"
else
    pular "Banco já existe"
    add_pulado "Banco de dados"
fi

# ============================================================================
# 3/10 — APACHE + PHP
# ============================================================================
titulo "3/10 — Apache + PHP"

if [ $APACHE_INSTALADO -eq 0 ]; then
    apt install -y -qq apache2 >/dev/null 2>&1
    add_ok "Apache2 instalado"
else
    pular "Apache2 já instalado"
    add_pulado "Apache2"
fi

if [ $PHP_INSTALADO -eq 0 ]; then
    apt install -y -qq php libapache2-mod-php php-cli php-mysql php-mbstring php-curl php-xml php-zip php-json php-gd php-bcmath >/dev/null 2>&1
    add_ok "PHP + extensões"
else
    for pkg in libapache2-mod-php php-mysql php-mbstring php-curl php-xml php-zip php-json php-gd php-bcmath; do
        pkg_instalado "$pkg" || apt install -y -qq "$pkg" >/dev/null 2>&1
    done
    pular "PHP já instalado"
    add_pulado "PHP"
fi

if cmd_existe php; then
    HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);" 2>/dev/null)
    [ -n "$HASH" ] && $ROOT_CMD ${DB_NAME} -e "
        INSERT IGNORE INTO usuarios (username, password, nome, email, nivel)
        VALUES ('${ADMIN_USER}', '${HASH}', 'Administrador', '${ADMIN_EMAIL}', 'admin');
    " 2>/dev/null && add_ok "Usuário admin"
fi

cat > /etc/apache2/mods-available/mpm_prefork.conf <<'MPMEOF'
<IfModule mpm_prefork_module>
    StartServers             3
    MinSpareServers          3
    MaxSpareServers          8
    MaxRequestWorkers       15
    MaxConnectionsPerChild  500
</IfModule>
MPMEOF

a2enmod rewrite headers >/dev/null 2>&1

cat > /etc/apache2/sites-available/dns-admin.conf <<EOF
<VirtualHost *:80>
    ServerName dns.local
    ServerAlias *
    DocumentRoot ${APP_DIR}/public

    <Directory ${APP_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/dns-admin-error.log
    CustomLog \${APACHE_LOG_DIR}/dns-admin-access.log combined
</VirtualHost>
EOF

a2dissite 000-default.conf >/dev/null 2>&1
a2ensite dns-admin.conf >/dev/null 2>&1

PHP_VER=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.2")
mkdir -p /etc/php/${PHP_VER}/apache2/conf.d
cat > /etc/php/${PHP_VER}/apache2/conf.d/99-dns-admin.ini <<'PHPINI'
upload_max_filesize = 32M
post_max_size = 32M
memory_limit = 512M
max_execution_time = 120
display_errors = Off
log_errors = On
error_log = /var/log/php-dns-admin.log
date.timezone = America/Sao_Paulo
PHPINI

mkdir -p /etc/php/${PHP_VER}/cli/conf.d
cat > /etc/php/${PHP_VER}/cli/conf.d/99-dns-admin.ini <<'PHPINI'
date.timezone = America/Sao_Paulo
memory_limit = 1024M
max_execution_time = 600
PHPINI

add_ok "Apache + PHP configurados"
systemctl enable apache2 >/dev/null 2>&1
systemctl restart apache2 >/dev/null 2>&1

# ============================================================================
# 4/10 — PHPMYADMIN
# ============================================================================
titulo "4/10 — phpMyAdmin"

if [ $PHPMYADMIN_INSTALADO -eq 0 ]; then
    debconf-set-selections <<< "phpmyadmin phpmyadmin/dbconfig-install boolean true"
    debconf-set-selections <<< "phpmyadmin phpmyadmin/app-password-confirm password ${DB_ROOT_PASS}"
    debconf-set-selections <<< "phpmyadmin phpmyadmin/mysql/admin-pass password ${DB_ROOT_PASS}"
    debconf-set-selections <<< "phpmyadmin phpmyadmin/mysql/app-pass password ${DB_ROOT_PASS}"
    debconf-set-selections <<< "phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2"
    apt install -y -qq phpmyadmin >/dev/null 2>&1
    pkg_instalado "phpmyadmin" && add_ok "phpMyAdmin instalado" || add_erro "phpMyAdmin"
else
    pular "phpMyAdmin já instalado"
    add_pulado "phpMyAdmin"
fi

if [ -d /usr/share/phpmyadmin ] && [ ! -f /etc/apache2/conf-available/phpmyadmin-dns-admin.conf ]; then
    cat > /etc/apache2/conf-available/phpmyadmin-dns-admin.conf <<'PMAEOF'
Alias /phpmyadmin /usr/share/phpmyadmin
<Directory /usr/share/phpmyadmin>
    Options FollowSymLinks
    DirectoryIndex index.php
    Require all granted
</Directory>
<Directory /usr/share/phpmyadmin/setup>
    Require local
</Directory>
<Directory /usr/share/phpmyadmin/libraries>
    Require all denied
</Directory>
<Directory /usr/share/phpmyadmin/templates>
    Require all denied
</Directory>
PMAEOF
    a2enconf phpmyadmin-dns-admin >/dev/null 2>&1
    systemctl reload apache2 >/dev/null 2>&1
    add_ok "Config phpMyAdmin"
fi

# ============================================================================
# 5/10 — BIND9
# ============================================================================
titulo "5/10 — Bind9"

if [ $BIND_INSTALADO -eq 0 ]; then
    apt install -y -qq bind9 bind9utils bind9-doc dnsutils >/dev/null 2>&1
    add_ok "Bind9 instalado"
else
    pular "Bind9 já instalado"
    add_pulado "Bind9"
fi

mkdir -p "$BIND_ZONES_DIR" "$BIND_LOG_DIR"
chown -R bind:bind /var/cache/bind "$BIND_LOG_DIR" 2>/dev/null
chmod 775 "$BIND_ZONES_DIR"

usermod -a -G bind www-data 2>/dev/null
log "www-data adicionado ao grupo bind"

if systemctl is-active --quiet apache2; then
    systemctl restart apache2 >/dev/null 2>&1
fi

[ ! -s /etc/bind/named.conf.local ] && cat > /etc/bind/named.conf.local <<'NLEOF'
//
// Arquivo gerenciado pelo DNS Admin
// NÃO EDITE MANUALMENTE
//
NLEOF

[ -f /etc/bind/named.conf.options ] && cp /etc/bind/named.conf.options /etc/bind/named.conf.options.bak.$(date +%s) 2>/dev/null

cat > /etc/bind/named.conf.options <<EOF
options {
    directory "/var/cache/bind";

    listen-on { any; };
    listen-on-v6 { none; };

    recursion yes;

    allow-recursion {
        127.0.0.1/32;
        ::1/128;
        172.16.0.0/12;
        10.0.0.0/8;
        192.168.0.0/16;
    };

    allow-query-cache {
        127.0.0.1/32;
        ::1/128;
        172.16.0.0/12;
        10.0.0.0/8;
        192.168.0.0/16;
    };

    allow-query { any; };

    dnssec-validation auto;

    max-cache-size 512m;
    max-cache-ttl 86400;
    max-ncache-ttl 10800;

    version "DNS Admin";
    hostname none;
    server-id none;

    allow-transfer { none; };
};
EOF
add_ok "Config named.conf.options"

if ! grep -q "category queries" /etc/bind/named.conf 2>/dev/null; then
    cat >> /etc/bind/named.conf <<EOF

logging {
    channel query_log {
        file "${BIND_LOG_DIR}/query.log" versions 10 size 500m;
        severity info;
        print-time yes;
        print-category yes;
        print-severity yes;
    };
    category queries { query_log; };
};
EOF
    add_ok "Log de queries"
else
    add_pulado "Log de queries"
fi

chown bind:bind /etc/bind/named.conf.local /etc/bind/named.conf.options 2>/dev/null
chmod 664 /etc/bind/named.conf.local /etc/bind/named.conf.options 2>/dev/null
chmod 755 /etc/bind

systemctl restart apache2 >/dev/null 2>&1

if sudo -u www-data test -w /etc/bind/named.conf.options 2>/dev/null; then
    log "www-data pode escrever em named.conf.options"
    add_ok "Permissões Bind (www-data)"
else
    erro "www-data NÃO pode escrever em named.conf.options"
    add_erro "Permissões Bind"
fi

if named-checkconf 2>/dev/null; then
    add_ok "Validação Bind"
    systemctl enable named >/dev/null 2>&1
    systemctl restart named >/dev/null 2>&1
    sleep 2
    systemctl is-active --quiet named && add_ok "Bind9 ativo" || add_erro "Bind9"
else
    erro "named-checkconf falhou"
    named-checkconf
    add_erro "Bind9 config inválida"
fi

cat > /etc/sudoers.d/dns-admin <<'SUDOEOF'
www-data ALL=(ALL) NOPASSWD: /usr/sbin/rndc reload, /usr/sbin/rndc reload *, /usr/sbin/rndc status, /usr/sbin/rndc stats, /usr/sbin/rndc flush, /usr/bin/systemctl status named, /usr/bin/systemctl is-active named, /usr/bin/systemctl restart named, /usr/bin/named-checkconf, /usr/bin/named-checkzone *
SUDOEOF
chmod 0440 /etc/sudoers.d/dns-admin
visudo -c -f /etc/sudoers.d/dns-admin >/dev/null 2>&1 && add_ok "Sudoers" || add_erro "Sudoers"

# ============================================================================
# 6/10 — DIRETÓRIOS
# ============================================================================
titulo "6/10 — Estrutura de diretórios"

mkdir -p "$APP_DIR"/{config,public,cache,scripts}
mkdir -p "$APP_DIR"/app/{controllers,models,views/layout}
mkdir -p "$APP_DIR"/public/assets/{css,js,img}
chown -R www-data:www-data "$APP_DIR"
chmod 755 "$APP_DIR/cache" "$APP_DIR/scripts"
add_ok "Estrutura de diretórios"

# ============================================================================
# 7/10 — DOWNLOAD DO GITHUB
# ============================================================================
titulo "7/10 — Baixando painel do GitHub"

if [ -f "$APP_DIR/public/dashboard.php" ]; then
    pular "Arquivos do painel já existem"
    add_pulado "Arquivos do painel"
else
    info "Baixando de: $GITHUB_URL"
    
    rm -f /tmp/dns-admin.zip
    wget -q "$GITHUB_URL" -O /tmp/dns-admin.zip 2>/dev/null
    
    if [ -f /tmp/dns-admin.zip ] && [ -s /tmp/dns-admin.zip ]; then
        TAMANHO=$(du -h /tmp/dns-admin.zip | cut -f1)
        log "Baixado: $TAMANHO"
        
        if unzip -t /tmp/dns-admin.zip >/dev/null 2>&1; then
            info "Extraindo..."
            rm -rf /tmp/dns-extract
            mkdir -p /tmp/dns-extract
            unzip -q /tmp/dns-admin.zip -d /tmp/dns-extract
            
            ORIGEM=""
            for pasta in /tmp/dns-extract/*/; do
                if [ -d "${pasta}app" ]; then
                    ORIGEM="${pasta%/}"
                    break
                fi
            done
            [ -z "$ORIGEM" ] && ORIGEM="/tmp/dns-extract"
            
            info "Origem: $ORIGEM"
            
            for dir in app public scripts config; do
                if [ -d "$ORIGEM/$dir" ]; then
                    cp -r "$ORIGEM/$dir" "$APP_DIR/" 2>/dev/null
                fi
            done
            
            if [ -f "$APP_DIR/public/dashboard.php" ]; then
                log "Arquivos do painel instalados"
                add_ok "Arquivos do painel (GitHub)"
            else
                erro "Estrutura inesperada no ZIP"
                add_erro "Extração"
            fi
            
            rm -rf /tmp/dns-extract /tmp/dns-admin.zip
        else
            erro "Arquivo baixado não é ZIP válido"
            add_erro "Download (arquivo inválido)"
            rm -f /tmp/dns-admin.zip
        fi
    else
        erro "Download falhou"
        warn "Verifique: $GITHUB_URL"
        add_erro "Download GitHub"
    fi
fi

chown -R www-data:www-data "$APP_DIR" 2>/dev/null
find "$APP_DIR" -type f -exec chmod 644 {} \; 2>/dev/null
find "$APP_DIR" -type d -exec chmod 755 {} \; 2>/dev/null

# ============================================================================
# 8/10 — CRIA O DATABASE.PHP COM A SENHA
# ============================================================================
titulo "8/10 — Criando database.php"

cat > "$APP_DIR/config/database.php" <<DBEOF
<?php
/**
 * Configuração do banco de dados
 * 
 * Este arquivo foi gerado automaticamente pelo instalador.
 * Gerado em: $(date '+%Y-%m-%d %H:%M:%S')
 */

define('DB_HOST', 'localhost');
define('DB_NAME', '${DB_NAME}');
define('DB_USER', 'root');
define('DB_PASS', '${DB_ROOT_PASS}');
define('DB_CHARSET', 'utf8mb4');

function db() {
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        try {
            \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException \$e) {
            die("Erro de conexão: " . \$e->getMessage());
        }
    }
    return \$pdo;
}
DBEOF

chmod 600 "$APP_DIR/config/database.php"
chown www-data:www-data "$APP_DIR/config/database.php"

if [ ! -f "$APP_DIR/config/database.php" ]; then
    erro "Falha ao criar database.php"
    add_erro "database.php"
else
    log "database.php criado"
    add_ok "database.php (criado)"
fi

# Teste robusto de conexão
RESULTADO_TESTE=$(sudo -u www-data php -r "
require '$APP_DIR/config/database.php';
try {
    \$count = db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    echo 'OK:' . \$count;
} catch (Exception \$e) {
    echo 'ERRO:' . \$e->getMessage();
}
" 2>&1)

if [[ "$RESULTADO_TESTE" == OK:* ]]; then
    USUARIOS=$(echo "$RESULTADO_TESTE" | cut -d: -f2)
    log "Conexão com o banco validada ($USUARIOS usuário(s))"
    add_ok "Conexão MariaDB"
else
    warn "Falha ao conectar: $RESULTADO_TESTE"
    add_erro "Conexão MariaDB"
fi

# ============================================================================
# 9/10 — CRON + CHART.JS
# ============================================================================
titulo "9/10 — Cron e Chart.js"

touch /var/log/dns-admin-analise.log
chown www-data:www-data /var/log/dns-admin-analise.log
chmod 644 /var/log/dns-admin-analise.log

if ! crontab -u www-data -l 2>/dev/null | grep -q "analisar_dns"; then
    (crontab -u www-data -l 2>/dev/null ; \
     echo "*/5 * * * * /usr/bin/php $APP_DIR/scripts/analisar_dns.php >> /var/log/dns-admin-analise.log 2>&1") | crontab -u www-data -
    add_ok "Cron"
else
    add_pulado "Cron"
fi

systemctl enable cron >/dev/null 2>&1
systemctl restart cron >/dev/null 2>&1

if [ ! -f "$APP_DIR/public/assets/js/chart.min.js" ]; then
    wget -q https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js \
        -O "$APP_DIR/public/assets/js/chart.min.js" 2>/dev/null
    [ -f "$APP_DIR/public/assets/js/chart.min.js" ] && add_ok "Chart.js" || add_erro "Chart.js"
else
    add_pulado "Chart.js"
fi

chown -R www-data:www-data "$APP_DIR" 2>/dev/null

# ============================================================================
# 10/10 — VERIFICAÇÃO FINAL
# ============================================================================
titulo "10/10 — Verificação final de permissões"

TESTES_OK=0
TESTES_ERRO=0

verificar() {
    local descricao="$1"
    local comando="$2"
    if eval "$comando" 2>/dev/null; then
        log "$descricao"
        ((TESTES_OK++))
    else
        erro "$descricao"
        add_erro "$descricao"
        ((TESTES_ERRO++))
    fi
}

verificar "www-data escreve em /etc/bind/named.conf.options" "sudo -u www-data test -w /etc/bind/named.conf.options"
verificar "www-data escreve em /etc/bind/named.conf.local" "sudo -u www-data test -w /etc/bind/named.conf.local"
verificar "www-data escreve em /var/cache/bind/zones" "sudo -u www-data test -w /var/cache/bind/zones"
verificar "www-data lê /var/log/named" "sudo -u www-data test -r /var/log/named"
verificar "www-data executa rndc status" "sudo -u www-data sudo /usr/sbin/rndc status"
verificar "Apache está rodando" "systemctl is-active --quiet apache2"
verificar "MariaDB está rodando" "systemctl is-active --quiet mariadb"
verificar "Bind9 está rodando" "systemctl is-active --quiet named"
verificar "Cron está rodando" "systemctl is-active --quiet cron"

echo ""
if [ $TESTES_ERRO -eq 0 ]; then
    add_ok "Permissões finais OK ($TESTES_OK testes passaram)"
else
    add_erro "Permissões: $TESTES_ERRO testes falharam"
fi

# ============================================================================
# RELATÓRIO FINAL
# ============================================================================
titulo "Instalação Concluída"

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║           ✅  INSTALAÇÃO CONCLUÍDA!                      ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

[ ${#REL_OK[@]} -gt 0 ] && {
    echo -e "${VERDE}▶ INSTALADO / CONFIGURADO:${NC}"
    for item in "${REL_OK[@]}"; do echo -e "   ${VERDE}✓${NC} $item"; done
    echo ""
}

[ ${#REL_PULADO[@]} -gt 0 ] && {
    echo -e "${CINZA}▶ JÁ EXISTIA (pulado):${NC}"
    for item in "${REL_PULADO[@]}"; do echo -e "   ${CINZA}⊘${NC} $item"; done
    echo ""
}

[ ${#REL_ERRO[@]} -gt 0 ] && {
    echo -e "${VERMELHO}▶ ATENÇÃO (verificar):${NC}"
    for item in "${REL_ERRO[@]}"; do echo -e "   ${VERMELHO}✗${NC} $item"; done
    echo ""
}

echo "═══════════════════════════════════════════════════════════"
echo -e "${CIANO}ACESSO:${NC}"
echo "═══════════════════════════════════════════════════════════"
echo ""
echo "  🌐 Painel:     http://${IP_SERVIDOR}/"
echo "     Usuário:    admin"
echo "     Senha:      (a que você definiu)"
echo ""
echo "  🗄️  phpMyAdmin: http://${IP_SERVIDOR}/phpmyadmin"
echo "     Usuário:    root"
echo "     Senha:      (a do banco)"
echo ""
echo "  📦 Repositório: https://github.com/${GITHUB_USER}/${GITHUB_REPO}"
echo ""
echo "═══════════════════════════════════════════════════════════"
echo -e "${AMARELO}⚠️  Senha do banco: ${DB_ROOT_PASS}${NC}"
echo "═══════════════════════════════════════════════════════════"
echo ""