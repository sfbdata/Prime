<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\MencoesDoRegistro;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Grava um registro (ou uma resposta) no Registro da pasta.
 *
 * Quando é RESPOSTA, o autor do comentário respondido (a raiz da conversa) recebe uma
 * notificação no sino — desenho `bluejus-central.js` `notificarResposta`, tipo "direcionada":
 * título "<Primeiro nome> respondeu seu comentário", texto com a resposta (140) e o comentário
 * original (80). Regras de quem recebe:
 *  - nunca quem respondeu (responder o próprio comentário não notifica);
 *  - só quem ainda tem vínculo ATIVO neste escritório E acesso de leitura a ESTA pasta
 *    (a mesma checagem da `pasta_show`) — sem acesso, o link levaria a uma tela negada;
 *  - a notificação é do escritório da pasta (Notificacao é TenantAware);
 *  - uma notificação POR RESPOSTA: duas pessoas (mesmo com o mesmo primeiro nome) ou a mesma
 *    pessoa respondendo duas vezes geram duas. O link aponta para a própria resposta
 *    (`#pasta-msg-<idDaResposta>`), e é ele que distingue uma da outra;
 *  - a anti-duplicata é só idempotência da MESMA resposta: se o destinatário já tem uma
 *    notificação deste tipo com este link (neste escritório), não cria outra.
 *
 * @MENÇÕES (item 20b) — vale para registro e para resposta. O conteúdo traz tokens
 * `@[Nome](user:ID)` (formato em {@see MencoesDoRegistro}). Depois de sanitizar:
 *  - cada id é conferido contra os colegas com vínculo ATIVO neste escritório (e usuário ativo).
 *    Id inexistente, de outro escritório ou de ex-colaborador perde a marcação e vira `@rótulo`
 *    em texto comum; id válido tem o rótulo trocado pelo nome do banco (o digitado não vale);
 *  - cada mencionado válido recebe `TIPO_PASTA_MENCAO_REGISTRO` — "<Primeiro nome> mencionou você
 *    na Pasta N · Dados da pasta" (desenho `menNotificar`), texto com a prévia (180), link para a
 *    própria mensagem (`#pasta-msg-<id>`) — com as MESMAS regras da resposta: nunca o autor, só
 *    quem tem vínculo ativo (a conferência acima) E acesso de leitura a ESTA pasta, idempotência
 *    por destinatário + escritório + tipo + link;
 *  - PRECEDÊNCIA: numa resposta, se o mencionado é o autor do comentário respondido, ele recebe só
 *    a notificação de RESPOSTA (mais específica: traz o comentário dele). A de menção é pulada —
 *    uma ação, um aviso. Quem é mencionado e não é o respondido recebe a de menção normalmente;
 *  - editar o registro depois (EditarMensagemPastaUseCase) não notifica nem relê menções.
 */
final class EnviarMensagemPastaUseCase
{
    /**
     * Onde o Registro mora na pasta_show — vai no texto da notificação. É o rótulo do desenho
     * (`regsLinha`: `abaRot = 'Dados da pasta'`), o que dá "em Dados da pasta da Pasta N".
     */
    private const ABA_DO_REGISTRO = 'Dados da pasta';

    private const LIMITE_RESPOSTA = 140;
    private const LIMITE_ORIGINAL = 80;
    /** Prévia da menção no sino — `menNotificar`: `prev.slice(0, 180) + '…'`. */
    private const LIMITE_MENCAO = 180;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly NotificacaoService $notificacaoService,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MencoesDoRegistro $mencoes,
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * @param PastaMensagem|null $respostaA o registro que esta mensagem responde. Tem de ser da
     *        MESMA pasta e do MESMO escritório — o id veio do navegador, então a posse é conferida
     *        aqui. Responder a uma resposta liga à RAIZ dela: a conversa tem um nível só.
     */
    public function executar(Pasta $pasta, User $autor, string $conteudo, Tenant $tenant, ?PastaMensagem $respostaA = null): PastaMensagem
    {
        // Vem do editor rico (HTML): limpo ANTES de persistir. Texto puro atravessa intacto.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        // `estaVazio` porque o editor entrega `<p><br></p>` quando nada foi digitado; o limite
        // conta o texto visível, não a marcação.
        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $raiz = null;
        if ($respostaA !== null) {
            $raiz = $respostaA->getRespostaA() ?? $respostaA;

            // A raiz também é conferida: barato, e não depende de o vínculo antigo estar são.
            foreach ([$respostaA, $raiz] as $alvo) {
                if (!self::mesmo($alvo->getTenant(), $tenant) || !self::mesmo($alvo->getPasta(), $pasta)) {
                    throw new \InvalidArgumentException('Só é possível responder a um registro desta pasta.');
                }
            }
        }

        [$conteudo, $mencionados] = $this->resolverMencoes($conteudo, $tenant);

        $mensagem = new PastaMensagem();
        $mensagem->setPasta($pasta);
        $mensagem->setAutor($autor);
        $mensagem->setTenant($tenant);
        $mensagem->setConteudo($conteudo);
        $mensagem->setRespostaA($raiz);

        if ($raiz === null && $mencionados === []) {
            $this->em->persist($mensagem);
            $this->em->flush();

            return $mensagem;
        }

        // Uma transação só: a mensagem e as notificações entram (ou não) juntas. O link da
        // notificação leva o id da MENSAGEM, que só existe depois do flush — por isso o flush
        // acontece DENTRO da transação, antes de criar as notificações; o
        // `wrapInTransaction` faz o flush final e o commit (ou o rollback, se algo falhar).
        return $this->em->wrapInTransaction(function () use ($mensagem, $raiz, $pasta, $autor, $tenant, $mencionados): PastaMensagem {
            $this->em->persist($mensagem);
            $this->em->flush();

            if ($raiz !== null) {
                $this->notificarAutorDoComentario($raiz, $mensagem, $pasta, $autor, $tenant);
            }
            $this->notificarMencionados($mencionados, $mensagem, $pasta, $autor, $tenant, $raiz?->getAutor());

            return $mensagem;
        });
    }

    /**
     * Confere os ids dos tokens contra os colegas ATIVOS deste escritório e grava a forma canônica.
     * Uma consulta só (a mesma lista que os menus de responsável usam), presa ao tenant.
     *
     * @return array{0: string, 1: list<User>} o conteúdo reescrito e os mencionados válidos
     */
    private function resolverMencoes(string $conteudo, Tenant $tenant): array
    {
        $ids = $this->mencoes->extrairIds($conteudo);
        if ($ids === []) {
            return [$conteudo, []];
        }

        $validos = [];
        $nomes   = [];
        foreach ($this->userRepository->findColaboradoresAtivosPorTenant($tenant) as $colega) {
            $id = $colega->getId();
            if ($id === null || !in_array($id, $ids, true) || !$colega->isActive()) {
                continue;
            }
            $validos[$id] = $colega;
            $nomes[$id]   = (string) $colega->getFullName();
        }

        // Na ordem em que aparecem no texto.
        $mencionados = [];
        foreach ($ids as $id) {
            if (isset($validos[$id])) {
                $mencionados[] = $validos[$id];
            }
        }

        return [$this->mencoes->reescrever($conteudo, $nomes), $mencionados];
    }

    /**
     * @param list<User> $mencionados já conferidos: vínculo ativo neste escritório
     * @param User|null  $destinatarioDaResposta o autor da raiz, quando a mensagem é resposta —
     *                   ele já recebe a notificação de resposta (precedência, ver a classe)
     */
    private function notificarMencionados(array $mencionados, PastaMensagem $mensagem, Pasta $pasta, User $autor, Tenant $tenant, ?User $destinatarioDaResposta): void
    {
        $pastaId = $pasta->getId();
        if ($mencionados === [] || $pastaId === null || $mensagem->getId() === null) {
            return;
        }

        $url    = $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]) . '#pasta-msg-' . $mensagem->getId();
        $titulo = sprintf(
            '%s mencionou você na Pasta %s · %s',
            self::primeiroNome($autor),
            ($nup = $pasta->getNup()) !== null && $nup !== '' ? $nup : '#' . $pastaId,
            self::ABA_DO_REGISTRO,
        );
        $previa = $this->textoPlano($mensagem->getConteudo());
        if (mb_strlen($previa) > self::LIMITE_MENCAO) {
            $previa = mb_substr($previa, 0, self::LIMITE_MENCAO) . '…';
        }

        foreach ($mencionados as $pessoa) {
            if (self::mesmoUsuario($pessoa, $autor)
                || ($destinatarioDaResposta !== null && self::mesmoUsuario($pessoa, $destinatarioDaResposta))) {
                continue;
            }

            if (!$this->permissionChecker->canAccessResource($pessoa, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
                continue;
            }

            $jaExiste = $this->notificacaoRepository->findOneBy([
                'usuario' => $pessoa,
                'tenant'  => $tenant,
                'tipo'    => Notificacao::TIPO_PASTA_MENCAO_REGISTRO,
                'url'     => $url,
            ]);
            if ($jaExiste !== null) {
                continue;
            }

            $this->notificacaoService
                ->criar($pessoa, $tenant, Notificacao::TIPO_PASTA_MENCAO_REGISTRO, $titulo, $previa)
                ->setUrl($url);
        }
    }

    /**
     * O destinatário é o autor da RAIZ: é a ela que a resposta fica pendurada na tela
     * ("Resposta a <nome>"), e é o id dela que o botão Responder manda.
     */
    private function notificarAutorDoComentario(PastaMensagem $raiz, PastaMensagem $resposta, Pasta $pasta, User $quemResponde, Tenant $tenant): void
    {
        $destinatario = $raiz->getAutor();
        if ($destinatario === null || self::mesmoUsuario($destinatario, $quemResponde)) {
            return;
        }

        $pastaId = $pasta->getId();
        if ($pastaId === null || $resposta->getId() === null) {
            return;
        }

        if (!$this->userTenantRepository->existeVinculoAtivo($destinatario, $tenant)
            || !$this->permissionChecker->canAccessResource($destinatario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            return;
        }

        $titulo = sprintf('%s respondeu seu comentário', self::primeiroNome($quemResponde));
        // A âncora é o cartão da PRÓPRIA resposta (`id="pasta-msg-N"` em _dados_anotacoes vale
        // para raiz e resposta): o link leva a ela, e cada resposta tem o seu.
        $url = $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]) . '#pasta-msg-' . $resposta->getId();

        // Idempotência da MESMA resposta: o link é único por resposta.
        $jaExiste = $this->notificacaoRepository->findOneBy([
            'usuario' => $destinatario,
            'tenant'  => $tenant,
            'tipo'    => Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO,
            'url'     => $url,
        ]);
        if ($jaExiste !== null) {
            return;
        }

        $texto = sprintf(
            '"%s" · em %s da Pasta %s · %s. Seu comentário: "%s"',
            mb_substr($this->textoPlano($resposta->getConteudo()), 0, self::LIMITE_RESPOSTA),
            self::ABA_DO_REGISTRO,
            ($nup = $pasta->getNup()) !== null && $nup !== '' ? $nup : '#' . $pastaId,
            $resposta->getCriadaEm()->format('d/m/Y H:i'),
            mb_substr($this->textoPlano($raiz->getConteudo()), 0, self::LIMITE_ORIGINAL),
        );

        $this->notificacaoService
            ->criar($destinatario, $tenant, Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO, $titulo, $texto)
            ->setUrl($url);
    }

    /**
     * O conteúdo é HTML do editor rico; o sino mostra texto. Fim de bloco e <br> viram espaço
     * (senão "linha um</p><p>linha dois" grudaria), entidades são decodificadas e os brancos
     * colapsados.
     */
    private function textoPlano(?string $html): string
    {
        $html  = preg_replace('#<br\s*/?>|</(p|li|h[1-6]|blockquote|pre)>#i', ' ', (string) $html) ?? '';
        $texto = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        // A menção aparece como a pessoa a vê na tela: `@Nome`, sem o token.
        $texto = $this->mencoes->paraTextoPlano($texto);

        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $texto) ?? '');
    }

    private static function primeiroNome(User $usuario): string
    {
        $nome = trim((string) $usuario->getFullName());
        if ($nome === '') {
            return 'Alguém';
        }

        return explode(' ', $nome)[0];
    }

    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /**
     * Mesma instância ou mesmo id (proxies do ORM). Dois objetos sem id NÃO são o mesmo:
     * sem id não há como provar a posse, e a dúvida recusa.
     */
    private static function mesmo(Tenant|Pasta|null $a, Tenant|Pasta $b): bool
    {
        if ($a === null) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
