# Automatisk deploy til simply.com

Hver gang der pushes til `main`, uploader GitHub Actions automatisk
indholdet af dette repo til webhotellet hos simply.com via FTP.

Workflowet ligger i [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml).

## Sådan virker det

- Repoet er et 1:1 spejl af FTP-roden: `public_html/` (hovedsitet) plus
  mapperne for alias-domænerne (`saaskobmaend.com/`, `saaskobmaend.dk/` osv.).
- Ved push til `main` synkroniseres repo-roden op til FTP-roden.
- Kun ændrede filer uploades (action'en husker tilstand i filen
  `.ftp-deploy-sync-state.json` på serveren).
- Følgende uploades **ikke**: `.git`, `.github/`, `.context/`,
  `.DS_Store`, `README.md` og `public_html/index.html-old`.

## Engangsopsætning: opret GitHub secrets

Du skal lægge dine FTP-oplysninger ind som "secrets" i GitHub, så de ikke
står i koden. På GitHub:

**Settings → Secrets and variables → Actions → New repository secret**

Opret disse tre:

| Secret          | Værdi                                                        |
| --------------- | ------------------------------------------------------------ |
| `FTP_SERVER`    | FTP-serverens hostname fra simply.com (fx `ssh.simply.com` eller den server der står i kontrolpanelet) |
| `FTP_USERNAME`  | Dit FTP-brugernavn                                           |
| `FTP_PASSWORD`  | Dit FTP-kodeord                                              |

### Hvor finder jeg FTP-oplysningerne?

Log ind i simply.com-kontrolpanelet → vælg webhotellet → **FTP / FTP-konti**.
Der står serveradresse, brugernavn, og du kan oprette/nulstille kodeord.

## Test af opsætningen

1. Push til `main` (eller kør workflowet manuelt under fanen **Actions**
   → *Deploy til simply.com (FTP)* → **Run workflow**).
2. Følg loggen under **Actions**.
3. Tjek at ændringen er live på sitet.

## Fejlfinding

- **TLS/FTPS-fejl:** Workflowet bruger `protocol: ftps` (krypteret).
  Hvis simply.coms server ikke svarer på FTPS, kan du ændre linjen til
  `protocol: ftp` i `deploy.yml`. (Foretræk FTPS hvis det virker.)
- **"server-dir" passer ikke:** Hvis du efter login via FTP allerede står
  *inde i* `public_html`, så skal `server-dir` ændres — kontakt evt.
  simply.com-support for at bekræfte FTP-rodens struktur.
- **Sletning af filer:** Action'en sletter kun filer den selv har lagt op
  (via state-filen). Filer der allerede ligger på serveren ved første kørsel
  bliver ikke rørt, medmindre de også findes i repoet.
