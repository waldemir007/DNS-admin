# Instalador Automático — DNS Admin

Script de instalação automática do **DNS Admin v1.0.3** em servidores Debian 12.

## 📋 O que ele faz

- ✅ Instala **MariaDB** + otimizações
- ✅ Instala **Apache2** + **PHP 8.2**
- ✅ Instala **phpMyAdmin**
- ✅ Instala **Bind9** + configuração otimizada
- ✅ Cria banco de dados + 6 tabelas
- ✅ Baixa os arquivos do painel do **GitHub**
- ✅ Cria `database.php` automaticamente com a senha
- ✅ Configura **cron** para análise de DNS
- ✅ Ajusta **permissões** (Bind, Apache, www-data)
- ✅ Executa **9 testes** de validação
- ✅ Gera **relatório final** detalhado

## 🚀 Como usar

### Passo 1 — Baixar o instalador

```bash
wget https://raw.githubusercontent.com/waldemir007/DNS-admin/main/installer/instalar-dns-admin.sh -O /root/instalar-dns-admin.sh
chmod +x /root/instalar-dns-admin.sh
