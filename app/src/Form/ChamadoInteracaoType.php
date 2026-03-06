<?php

namespace App\Form;

use App\Entity\ServiceDesk\ChamadoInteracao;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChamadoInteracaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isAdmin = $options['is_admin'];

        $builder
            ->add('mensagem', TextareaType::class, [
                'label' => 'Mensagem',
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'Digite sua mensagem ou resposta...',
                ],
            ]);

        // Apenas administradores podem marcar como interno
        if ($isAdmin) {
            $builder->add('interno', CheckboxType::class, [
                'label' => 'Comentário interno (visível apenas para a equipe de TI)',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChamadoInteracao::class,
            'is_admin' => false,
        ]);

        $resolver->setAllowedTypes('is_admin', 'bool');
    }
}
