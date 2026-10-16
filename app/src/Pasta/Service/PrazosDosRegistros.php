<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPrazo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mantém os prazos LIDOS dos registros da pasta (desenho 1.2.3, `pzLista` sobre os registros com `lerDatas`, E12c).
 *
 * Lê o Registro dos expedientes, os Detalhes e o Financeiro da pasta (sem os cartões do sistema, que só repetem o
 * que já é prazo), passa cada texto pelo {@see LeitorDeDatas} com a data do próprio registro como base, tira os
 * repetidos (mesmo tipo e mesma data na pasta, como o desenho) e acerta a tabela: cria o que apareceu, apaga o que
 * sumiu, não mexe no que continua igual. Os prazos manuais não são tocados. Quem chama dá o flush.
 */
final class PrazosDosRegistros
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{criados: int, apagados: int} */
    public function sincronizar(Pasta $pasta): array
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return ['criados' => 0, 'apagados' => 0];
        }

        $desejados = [];
        $vistos = [];
        foreach ($this->registros($pasta) as [$ref, $html, $escritoEm, $autor]) {
            foreach (LeitorDeDatas::ler(LeitorDeDatas::textoDoHtml($html), $escritoEm) as $d) {
                $chaveDia = $d['tipo'] . $d['data'];
                if (isset($vistos[$chaveDia])) {
                    continue;
                }
                $vistos[$chaveDia] = true;
                $desejados[self::chave($ref, $d['tipo'], $d['data'], $d['hora'], $d['titulo'], $d['estimado'])] = [$ref, $escritoEm, $autor, $d];
            }
        }

        $existentes = $this->em->getRepository(PastaPrazo::class)->findBy(['pasta' => $pasta, 'tenant' => $tenant, 'origem' => PastaPrazo::ORIGEM_REGISTRO]);
        $apagados = 0;
        foreach ($existentes as $prazo) {
            $k = self::chave((string) $prazo->getRegistroRef(), $prazo->getTipo(), $prazo->getData()->format('Y-m-d'), (string) $prazo->getHora(), $prazo->getTitulo(), $prazo->isEstimado());
            if (isset($desejados[$k])) {
                unset($desejados[$k]);
                continue;
            }
            $this->em->remove($prazo);
            ++$apagados;
        }

        foreach ($desejados as [$ref, $escritoEm, $autor, $d]) {
            $this->em->persist(PastaPrazo::doRegistro($tenant, $pasta, $ref, $escritoEm, $autor, $d['tipo'], $d['titulo'],
                new \DateTimeImmutable($d['data']), $d['hora'] !== '' ? $d['hora'] : null, $d['estimado']));
        }

        return ['criados' => count($desejados), 'apagados' => $apagados];
    }

    /**
     * Lidos do banco, não das coleções da pasta: dentro do mesmo request a coleção pode estar carregada de antes da
     * escrita que disparou a releitura.
     *
     * @return iterable<array{0: string, 1: string, 2: \DateTimeImmutable, 3: ?User}>
     */
    private function registros(Pasta $pasta): iterable
    {
        $fontes = [
            'expedientes' => [PastaMensagem::class, ' AND r.sistema IS NULL'],
            'detalhes' => [PastaObservacaoDetalhes::class, ''],
            'financeiro' => [PastaObservacaoFinanceira::class, ''],
        ];
        foreach ($fontes as $prefixo => [$classe, $extra]) {
            $linhas = $this->em->createQuery('SELECT r FROM ' . $classe . ' r WHERE r.pasta = :p' . $extra . ' ORDER BY r.id')
                ->setParameter('p', $pasta)->getResult();
            foreach ($linhas as $r) {
                yield [$prefixo . ':' . $r->getId(), (string) $r->getConteudo(), $r->getCriadaEm(), $r->getAutor()];
            }
        }
    }

    private static function chave(string $ref, string $tipo, string $data, string $hora, string $titulo, bool $estimado): string
    {
        return implode('|', [$ref, $tipo, $data, $hora, mb_substr($titulo, 0, 255), $estimado ? '1' : '0']);
    }
}
