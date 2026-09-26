# WhatsApp via Evolution API (opção C)

O SINERGIA BOT ML fala com o WhatsApp só pelo contrato `WhatsAppProvider`. Há duas implementações:

| Provedor | Variáveis | Criação de instância |
|---|---|---|
| `uazapi` (padrão) | `UAZAPI_BASE_URL`, `UAZAPI_ADMIN_TOKEN` | automática, pelo BotML |
| `evolution` | `WHATSAPP_PROVIDER=evolution`, `EVOLUTION_BASE_URL` (opcional: `EVOLUTION_HTTP_TIMEOUT_SECONDS`, 1–60, padrão 15) | **administrativa, fora do BotML** |

## Versão validada

A implementação (`src/Integration/WhatsApp/Evolution/EvolutionProvider.php`) foi escrita e testada contra a
**Evolution API 2.3.7** (tag oficial `2.3.7`, Baileys `7.0.0-rc.9`). Não atualize a Evolution sem revalidar:
as versões **2.4.x** têm mudanças de licenciamento/ativação e estão fora do escopo.

## Por que "opção C"

A chave **global** da Evolution (`AUTHENTICATION_API_KEY` do servidor Evolution) dá poder sobre TODAS as instâncias do
servidor. Por isso ela **nunca** é configurada, lida ou usada pelo BotML (não existe `EVOLUTION_API_KEY`). Na 2.3.7 a
global só é indispensável para `POST /instance/create`; todas as operações do dia a dia aceitam o token da própria
instância no cabeçalho `apikey`:

| Operação | Rota (2.3.7) |
|---|---|
| QR / código de pareamento | `GET /instance/connect/{instância}[?number=]` |
| Estado | `GET /instance/connectionState/{instância}` (`open` / `connecting` / `close`) |
| Número e perfil | `GET /instance/fetchInstances` (só devolve a instância do token) |
| Desconectar | `DELETE /instance/logout/{instância}` (a instância e o token continuam válidos) |
| Grupos | `GET /group/fetchAllGroups/{instância}?getParticipants=false` |
| Dados do grupo | `GET /group/findGroupInfos/{instância}?groupJid=` |
| Link de convite (só admin) | `GET /group/inviteCode/{instância}?groupJid=` |
| Aprovação para entrar | `GET /group/inviteInfo/{instância}?inviteCode=` (`joinApprovalMode`) |
| Envio de imagem + legenda | `POST /message/sendMedia/{instância}` |

Canais (newsletter) não existem na 2.3.7: `listChannels()` devolve lista vazia (canais fora do F1).

## Liberar o WhatsApp para uma conta

1. **Administrador, fora do BotML**: cria uma instância exclusiva para a conta (ex.: `sbm-1-botml`) no Evolution
   Manager (`/manager`) ou por `POST /instance/create` com a chave global, e anota o nome e o token (`hash`) dela.
2. **Administrador, no console do serviço do BotML**:

   ```bash
   php bin/console whatsapp:instance:assign --installation=default --instance=sbm-1-botml
   ```

   O token é pedido duas vezes, **sem eco**. O comando grava só `evo1:<instância>:<token>`, cifrado com a `APP_KEY`
   (mesmo campo e mesma criptografia já usados). Não chama a Evolution, não exibe o token e recusa: conta inexistente,
   nome inválido, instância já atribuída a outra conta e conta com WhatsApp conectado/conectando.
3. **Cliente, no painel**: Conexões › WhatsApp › *Conectar com QR code* → lê o QR → *Conectado*.

Conta sem instância atribuída vê "WhatsApp ainda não está liberado para esta conta." e o BotML **não** tenta criar
instância. Se a instância for removida (ou o token trocado) direto na Evolution, a próxima conexão recebe 401/404: a
credencial é esquecida e a conta volta para "não liberado" até nova atribuição. Nada é reenviado.

## Tradução para as regras de destino (sem mudar `DestinationEligibility`)

- Nosso número é admin: obter o código de convite prova (só admin obtém); sem código, procura o número da instância
  (`ownerJid`) nos participantes. Participante só com LID (sem `phoneNumber`) → desconhecido (`null`).
- Link de convite: código obtido → sim; senão → desconhecido.
- Aprovação para entrar: `joinApprovalMode` do `inviteInfo`; indisponível → desconhecido.
- Comunidade: `isCommunity`, `isCommunityAnnounce` ou `linkedParent`.
Dado desconhecido nunca vira "sim" nem "não". O comportamento com o grupo real será validado no primeiro uso.

## Licença da Evolution API 2.3.7

Apache 2.0 com condições adicionais do produtor (`LICENSE` da tag 2.3.7). A cláusula 1.b exige um aviso claro,
visível aos administradores, de que o sistema usa a Evolution API: o card WhatsApp de Conexões mostra esse aviso
quando `WHATSAPP_PROVIDER=evolution`. Não há parceria, certificação ou vínculo do SINERGIA BOT ML com a Evolution API.
