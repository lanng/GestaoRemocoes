# GestaoRemocoes

[English](README.md)

CopartRemocoes é uma aplicação web operacional que coordena remoções de veículos com perda total entre pedidos de seguradoras e operações de transporte. O sistema continua em uso operacional e está hospedado em um VPS.

## Fluxo de negócio

Quando uma seguradora declara perda total de um veículo, o pedido de remoção inicia um fluxo que acompanha o veículo, a seguradora, as cidades de origem e destino, os prazos de coleta e entrega e a situação operacional. A equipe acompanha cada remoção da coleta à entrega e prepara os registros concluídos para o processamento de pagamentos.

## Funcionalidades

- **Registro operacional:** Tabela no formato de planilha para buscar e filtrar registros, atualizar situações, destacar prazos e consultar dados do veículo e da rota. A busca aceita várias placas coladas. Os registros selecionados podem ser exportados para Excel ou JSON, ou copiados como imagem.
- **PDFs de pedidos de remoção:** O `pdftotext`, do Poppler, extrai campos compatíveis com formatos de PDF conhecidos e preenche o formulário. A equipe revisa os dados extraídos antes de salvar; trata-se de extração de texto, não de OCR genérico.
- **Fluxos por e-mail:** Os fluxos integrados ao Microsoft Graph processam pedidos de remoção, checklists digitais e avisos de erro de CT-e. Uma caixa de entrada de integrações oferece revisão humana e conciliação, com novas tentativas para importações elegíveis de pedidos de remoção. A sincronização de checklists é agendada a cada cinco minutos quando o scheduler da aplicação está ativo.
- **Documentos de transporte brasileiros:** Fluxos configuráveis cobrem CT-e (Conhecimento de Transporte Eletrônico) e MDF-e (Manifesto Eletrônico de Documentos Fiscais), transmitidos à SEFAZ (Secretarias de Fazenda estaduais), além do CIOT (Código Identificador da Operação de Transporte), transmitido à ANTT (Agência Nacional de Transportes Terrestres). As funcionalidades incluem geração de documentos, novas tentativas, conciliação e encerramento coordenado. Há configurações de simulação e homologação, além de modos de transmissão em produção protegidos por controles explícitos. O uso em produção depende da configuração, dos certificados, das credenciais e das flags de habilitação de cada ambiente; a implementação no código não significa que todas as integrações estejam ativas em produção.
- **Lotes de pagamento:** Registros entregues podem ser agrupados em lotes para revisão e confirmação manual na aplicação. O fluxo não inicia transferências bancárias.
- **Histórico de atividades:** A aplicação registra alterações em campos selecionados e ações de fluxos importantes, associadas ao usuário responsável.
- **Armazenamento de documentos:** PDFs de pedidos de remoção são enviados ao S3 com visibilidade pública. Documentos fiscais DFe usam um disco S3 privado separado.

## Tecnologias

- PHP 8.2+, Laravel 12, Filament 3 e Livewire 3
- Tailwind CSS 4, Alpine.js 3 e Vite 6
- Configurações para MySQL e SQLite; o ambiente de exemplo usa SQLite
- Poppler para extração de texto dos PDFs compatíveis

## Configuração local

Requisitos: PHP 8.2+, Composer, Node.js (22+ recomendado) e Poppler para extração de texto dos PDFs. No Debian/Ubuntu, instale o Poppler com `sudo apt install poppler-utils`; no macOS, use `brew install poppler`. No Windows, a dependência pode ser instalada no WSL.

```bash
git clone https://github.com/lanng/CopartRemocoes.git
cd CopartRemocoes
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan make:filament-user
composer run dev
```

O ambiente de exemplo usa SQLite, e o arquivo `database/database.sqlite` precisa existir antes de executar as migrações. `make:filament-user` solicita os dados da conta. Acesse `/admin` e entre no painel. `composer run dev` inicia o servidor web, o listener da fila, a saída de logs e o Vite; ele não inicia o scheduler. Defina `PAYMENT_BATCH_START_DATE` antes de testar localmente a geração agendada de lotes de pagamento. `php artisan schedule:work` executa todas as tarefas agendadas, inclusive as rotinas de limpeza mencionadas abaixo.

Configure as integrações no seu próprio `.env` somente quando necessário. O painel não exige credenciais do Microsoft Graph ou dos serviços fiscais para iniciar. Os uploads de PDFs de registros usam explicitamente o disco `s3`: defina `AWS_BUCKET` com o bucket real e configure as credenciais e, se aplicável, o endpoint. `FILESYSTEM_DISK=local` não redireciona esses uploads. Apesar do nome, a aplicação usa `AWS_S3_BUCKET` como diretório/prefixo de upload. O armazenamento de documentos fiscais pode usar `DFE_S3_BUCKET` e `DFE_S3_PREFIX` no disco privado separado.

## Verificações

```bash
php artisan test --compact
npm run build
```

## Implantação em VPS

A aplicação está hospedada em um VPS. Configure a raiz pública do servidor web como `public/`, gere os assets do frontend, aplique as migrações, mantenha um worker da fila supervisionado e configure o scheduler do Laravel para executar a cada minuto quando houver fluxos agendados. Instale o Poppler e configure S3, Microsoft Graph, certificados e credenciais fiscais para as integrações habilitadas naquele ambiente.

Antes de ativar o scheduler, revise as tarefas agendadas: elas incluem geração de lotes de pagamento e rotinas de retenção. Defina `PAYMENT_BATCH_START_DATE` para o processamento de pagamentos. A rotina de limpeza remove registros pagos ou cancelados com mais de 15 dias e os respectivos arquivos no S3. Outras rotinas removem itens elegíveis já resolvidos da caixa de integrações por e-mail com mais de 30 dias e lotes de pagamento confirmados há mais de 90 dias.
