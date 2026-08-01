# CineFio no Render com Supabase

## 1. Obter a conexao correta no Supabase

1. Abra o projeto no Supabase.
2. Clique em **Connect**.
3. Selecione **Session pooler**.
4. Copie a URI da porta `5432`.
5. Substitua o marcador da senha pela senha do banco.

Use o Session pooler porque o endereco direto `db.PROJECT_REF.supabase.co` usa IPv6, enquanto o Render precisa da conexao IPv4 do Supavisor.

Nao salve a URI real em arquivos, commits, screenshots ou mensagens. A chave publishable do Supabase nao e usada pelo backend PHP.

## 2. Criar o servico no Render

1. Envie o projeto para um repositorio privado no GitHub.
2. No Render, escolha **New > Blueprint** e conecte o repositorio.
3. Confirme o servico `cinefio-api` detectado pelo `render.yaml`.
4. Quando solicitado, cadastre `DATABASE_URL` com a URI completa do Session pooler.
5. Inicie o deploy.

Alternativamente, crie um **Web Service**, selecione runtime **Docker**, aponte para o mesmo repositorio e cadastre `DATABASE_URL` em **Environment**.

## 3. Validar o backend

Depois do deploy, abra:

```text
https://SEU-SERVICO.onrender.com/health.php
```

O retorno esperado e:

```json
{"status":"ok","service":"cinefio-api"}
```

Depois abra `/login` ou chame `/api/v1/auth/me`. No primeiro acesso que inicializa a aplicacao, o CineFio cria as tabelas ausentes no Supabase.

## 4. Apontar o aplicativo mobile

Atualize `mobile/.env`:

```text
EXPO_PUBLIC_API_URL=https://SEU-SERVICO.onrender.com
```

Reinicie o Expo limpando o cache:

```powershell
cd mobile
npx expo start --clear
```

Uma APK gerada anteriormente mantem a URL compilada. Gere uma nova APK depois de definir a URL definitiva do Render.
