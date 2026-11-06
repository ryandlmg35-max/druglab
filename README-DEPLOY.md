# DrugLab Clone — Deploy na web normal

Clone funcional em PHP 8 + SQLite: catálogo, carrinho com desconto por quantidade, checkout, pagamento em 9 criptomoedas indo direto para as SUAS carteiras, contas de usuário, tickets e painel admin.

## 1. Requisitos
- PHP 8.1+ com `pdo_sqlite`, `openssl`, `curl`
- Apache com `mod_rewrite` (ou Nginx equivalente)
- Escrita na pasta `data/`

## 2. Upload
1. Envie todo o conteúdo para `public_html` (ou subpasta).
2. Acesse `https://seudominio.com/install.php` — cria o banco e importa 41 produtos.
3. **Apague `install.php`** depois.

## 3. Carteiras (o ponto principal)
Edite `app/config.php`, bloco `wallets`. Troque cada `COLE_SEU_ENDERECO_..._AQUI` pelo endereço da sua carteira. O checkout grava o endereço no pedido e a página de pagamento mostra exatamente ele + QR.

Endereços vazios/placeholder = cliente vê placeholder. Confira com:

```bash
php -r 'print_r(require "app/config.php");' | findstr COLE_
```

Deve retornar vazio depois de configurar.

## 4. Admin
- URL: `?page=admin`
- Senha inicial: `changeme`
- Troque em Admin → Password.

Admin permite: ver pedidos, descriptografar endereço de entrega, mudar status, editar produtos/preços/faixas, fechar tickets.

## 5. Banco e backup
- Banco: `data/shop.sqlite`
- Chave de criptografia: `data/app.key` (não perca — sem ela os endereços de entrega ficam ilegíveis)
- Backup: copie `data/shop.sqlite` + `data/app.key`.

## 6. HTTPS e CDN
- Cloudflare proxy laranja: SSL/TLS = Full (strict), Always Use HTTPS ON.
- Não ative "Automatic HTTPS Rewrites" se usar espelho .onion.
- WAF: bloqueie métodos além de GET/POST e paths `app/`, `data/`.

## 6b. Nginx (equivalente ao .htaccess)
```nginx
location ~ ^/(app|data)/ { deny all; return 403; }
location ~* \.(sqlite|db|key|hash|log|json|md)$ { deny all; return 403; }
```

## 7. Endurecimento
- `data/` e `app/` já vêm bloqueados via `.htaccess`; confirme com `curl -I https://seudominio.com/data/shop.sqlite` → 403.
- Mínimo de pedido e frete ficam em `app/config.php` (`min_order_usd`, `shipping_usd`, `free_shipping_over`).
- Cotações vêm da CoinGecko com cache de 10 min em `data/rates.json`; fallback em `fallback_rates`.
- Sessões: cookie `HttpOnly`, `SameSite=Lax`, regeneração no login.
- Senhas: `password_hash` (bcrypt). Entrega: AES-256-GCM.

## 8. Operação (segurança física e lógica)
- Hospedagem: use provedor offshore/bulletproof; não hospede no seu país se a operação for ilegal ali.
- Domínio: registrador com privacy e pagamento em crypto; TTL baixo para rotação rápida.
- Servidor: SSH só por WireGuard/VPN, chaves Ed25519 em hardware token, sem porta 22 exposta.
- Logs: retenção curta; error_log em `data/` já bloqueado.
- Não reutilize usuário, e-mail, foto ou carteira entre projetos.
- Teste o fluxo completo em staging: registro → carrinho → checkout → QR → "I have paid" → admin marca Pago.

## 9. Estrutura
```
index.php              front controller / rotas
install.php            instalador (apagar após uso)
.htaccess              bloqueio de app/ e data/
app/config.php         site, carteiras, frete, taxas
app/helpers.php        banco, preço, crypto, CSRF, AES
app/views/             templates
app/catalog.json       catálogo raspado (41 produtos)
assets/                CSS, imagens, fontes, QR
data/                  SQLite, chave, senha admin, caches (protegido)
```
