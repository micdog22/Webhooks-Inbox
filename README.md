# MicDog Webhooks Inbox (PHP + HTML/JS)

Coletor e inspetor de webhooks simples e completo. Permite criar canais com tokens, receber requisições em endpoints únicos e visualizar cada evento com cabeçalhos, querystring, corpo e metadados. Suporta exportação e reenvio de eventos para outra URL.

Recursos:
- Canais com token único e endpoint público
- Captura de qualquer método HTTP
- Armazena cabeçalhos, query, path, método, IP, user-agent e corpo bruto
- Busca e filtros por canal, período e texto
- Estatísticas de eventos por dia (gráfico)
- Visualizador com formatação JSON quando aplicável
- Exportação CSV dos eventos
- Reenvio de evento (replay) para uma URL definida
- Banco SQLite via PDO (zero dependências)
- API REST com proteção CSRF para operações administrativas

## Requisitos
- PHP 8.1+ com PDO SQLite habilitado
- Extensão cURL para reenvio (opcional, apenas para o endpoint /replay)
- Navegador moderno

## Como rodar
Na raiz do projeto:

```bash
php -S localhost:8080 -t .
```

Acesse o painel:
```
http://localhost:8080/public/
```

Recebimento de webhooks:
- Cada canal possui um endpoint do tipo:
```
http://localhost:8080/api/incoming/{token}
```

## API (resumo)
Todas as requisições não-GET (exceto /incoming/{token}) exigem `X-CSRF-Token`, obtido em `GET /api/csrf`.

- `GET  /api/csrf`
- `GET  /api/channels?q=...`
- `POST /api/channels`           body JSON: `{name, token?}`
- `DELETE /api/channels/{id}`
- `GET  /api/events?channel_id=&from=&to=&q=`
- `GET  /api/events/{id}`
- `DELETE /api/events/{id}`
- `GET  /api/export/events?channel_id=&from=&to=&q=`  (CSV)
- `POST /api/events/{id}/replay` body JSON: `{url, method?, headers?, bodyMode?}`
- `ANY  /api/incoming/{token}`   público, captura o evento e retorna `{ok, id}`

`bodyMode`: `"original"` (default) ou `"raw"` com `rawBody` dentro do JSON.

## Estrutura
```
micdog-webhooks-inbox-php/
├─ README.md
├─ .gitignore
├─ data/
├─ api/
│  └─ index.php
└─ public/
   ├─ index.html
   ├─ app.css
   └─ app.js
```

## Licença
MIT
