# Deploy no Railway.app

## Pré-requisitos
- Conta no [Railway.app](https://railway.app) (grátis $5/mês crédito)
- Repositório no GitHub

## Deploy (3 passos)

### 1. Push pro GitHub
```bash
cd druglab-clone
git init
git add .
git commit -m "DrugLab para Railway"
git branch -M main
git remote add origin https://github.com/SEU_USUARIO/druglab.git
git push -u origin main
```

### 2. Cria projeto no Railway
1. Acesse [railway.app](https://railway.app) → login com GitHub
2. "New Project" → "Deploy from GitHub repo"
3. Seleciona seu repo `druglab`
4. Railway detecta `Dockerfile` automaticamente

### 3. Configura variáveis de ambiente
No painel do Railway → Variables, adicione:

| Variável | Valor | Obrigatório |
|----------|-------|-------------|
| `APP_KEY` | *(gere uma)* `php -r "echo base64_encode(random_bytes(32));"` | **Sim** |
| `PIX_KEY` | `71578786150` (seu CPF) | Sim |

### 4. Deploy
Railway builda a imagem Docker e sobe. URL final: `https://seu-projeto.up.railway.app`

---

## Pós-deploy

### 1. Instalação
Acesse `https://seu-projeto.up.railway.app/install.php` → cria banco + importa 41 produtos

### 2. Configura carteiras
Edite `app/config.php` (no Railway: Services → Files → edita) ou committa no GitHub:
```php
'wallets' => [
    'BTC' => ['address' => 'SEU_ENDERECO_BTC', ...],
    'ETH' => ['address' => 'SEU_ENDERECO_ETH', ...],
    'PIX' => ['address' => '71578786150', ...],  // seu CPF
],
```

### 3. Admin
- `https://seu-projeto.up.railway.app/?page=admin`
- Senha inicial: `changeme` → **troque em Admin → Password**

### 4. Apaga install.php
```bash
# No Railway: Services → Shell
rm install.php
```

---

## Estrutura de arquivos importante

```
druglab-clone/
├── Dockerfile              # Build da imagem
├── docker/
│   ├── apache.conf         # Config Apache
│   └── entrypoint.sh       # Entrypoint (handle PORT)
├── railway.toml            # Config Railway
├── .dockerignore           # Ignora no build
├── app/
│   ├── config.php          # SUAS CARTEIRAS AQUI
│   └── ...
└── data/                   # SQLite + keys (persistido pelo Railway)
```

---

## Comandos úteis no Railway Shell

```bash
# Ver logs
tail -f /var/log/apache2/error.log

# Ver banco
sqlite3 data/shop.sqlite ".tables"

# Backup do banco
cp data/shop.sqlite data/shop_backup_$(date +%Y%m%d).sqlite

# Reset admin password
php -r "echo password_hash('nova_senha', PASSWORD_DEFAULT);" > data/admin.hash
```

---

## Limitações do Free Tier

- **$5/mês crédito** → ~720h/mês (sempre ligado cabe no free)
- **512MB RAM** compartilhada
- **1GB disco** (SQLite + assets cabe tranquilo)
- **Spin-down** após inatividade (pode demorar 10-30s no primeiro acesso)

---

## Domínio próprio (opcional)

1. Railway → Settings → Domains → "Custom Domain"
2. Adiciona `seu-dominio.com` → configura DNS:
   - Type: CNAME
   - Name: @ (ou www)
   - Value: `seu-projeto.up.railway.app`
3. SSL automático (Let's Encrypt)

---

## Troubleshooting

| Problema | Solução |
|----------|---------|
| Build falha | Verifica logs no Railway → Build Logs |
| 500 Internal Error | `tail -f /var/log/apache2/error.log` no Shell |
| SQLite locked | Verifica permissões `data/` (www-data:www-data) |
| APP_KEY missing | Gera no Railway Variables: `php -r "echo base64_encode(random_bytes(32));"` |
| PIX não funciona | Verifica se `PIX_KEY` tá nas Variables |