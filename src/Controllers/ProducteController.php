<?php


namespace App\Controllers;


use App\Core\App;
use App\Core\Controller;
use App\Core\Exception\NotFoundException;
use App\Core\Router;
use App\Core\Security;
use App\Entity\Producte;
use App\Exception\UploadedFileException;
use App\Exception\UploadedFileNoFileException;
use App\Model\categoriaModel;
use App\Model\producteModel;
use App\Model\UserModel;
use App\Utils\UploadedFile;
use Exception;
use PDOException;

class ProducteController extends Controller
{

    public function index(): string
    {
        try {


            $producteModel = App::getModel(producteModel::class);
            $categoriaModel = App::getModel(categoriaModel::class);
            $categories = $categoriaModel->findAll();


            //Codi per a traure el usuari (part de traure els productes per usuari)

            //En aquest cas podria optimitzar el codi fent la instancia de user abans i $user->getRole() == "ROLE_ADMIN
            if($_SESSION["role"] == "ROLE_ADMIN" || $_SESSION["role"] == "ROLE_SUPERADMIN"){

                $productes = $producteModel->findAll(["nom" => "ASC"]);

            }else{
                //Usuari conectat
                $user = App::get("user");
                $id = $user->getId();

                $productes = $producteModel->findBy(["usuari_id"=>$id]);
            }

        } catch (PDOException $PDOException) {
            echo $PDOException->getMessage();
        } catch (Exception $exception) {
            echo $exception->getMessage();
        }

        if(!empty($_SESSION["loggedUser"])){

            $messageUser = $_SESSION["loggedUser"];

        }else{
            $messageUser="";
        }


        $title = "Agrow";

        $router = App::get(Router::class);

        return $this->response->renderView("productes", "default", compact('title', 'productes', 'router','messageUser','categories'));

    }

    public function create(): string
    {
        $categoriaModel = new CategoriaModel(App::get("DB"));
        $categories = $categoriaModel->findAll(["nom" => "ASC"]);


        $title = "Nou producte - Agrow";
        return $this->response->renderView("productes-create", "default", compact('title','categories'));
    }
    public function store(): string
    {

        $categoriaModel = new CategoriaModel(App::get("DB"));
        $categories = $categoriaModel->findAll(["nom" => "ASC"]);

        $errors = [];
        $title = "Nou Producte";

        $nom = filter_input(INPUT_POST, "nom");
        if (empty($nom)) {
            $errors[] = "No pots deixar el nom buit";
        }
        $descripcio = filter_input(INPUT_POST, "descripcio");
        if (empty($descripcio)) {
            $errors[] = "No pots deixar la descripcio buida";
        }
        $preu = filter_input(INPUT_POST, "preu");
        if (empty($preu)) {
            $errors[] = "No pots deixar el producte sense preu";
        }
        if ($preu>1000) {
            $errors[] = "Els productes no poden superar els 1000 euros";
        }


        $categoria_id = filter_input(INPUT_POST, "categoria");

        //App get user i agafar la id
        //TODO: UTILITZAR EL APP GET USER I FICAR LA ID DES DE AHI
        $usuari_id = $_SESSION["loggedUser"];

        if (empty($errors)) {
            try {
                $uploadedFile = new UploadedFile("poster", 2000 * 1024, ["image/jpeg", "image/jpg","image/png"]);

                if ($uploadedFile->validate()) {
                    $uploadedFile->save(Producte::POSTER_PATH, uniqid("MOV"));
                    $filename = $uploadedFile->getFileName();
                }
            } catch (Exception $exception) {
                $errors[] = "Error uploading file ($exception)";
            }
        }


        if (empty($errors)) {
            try {
                $producte = new Producte();
                $producte->setNom($nom);
                $producte->setDescripcio($descripcio);
                $producte->setPreu($preu);
                $producte->setPoster($filename);
                $producte->setUsuariId($usuari_id);
                $producte->setCategoriaId($categoria_id);


                $producteModel = App::getModel(producteModel::class);
                $producteModel->save($producte);

                $flash = App::get("flash");
                $flash::set("message", "El producte s'ha creat correctament");
                $redirect = App::get("router");
                //$redirect::redirect('productes');

            } catch (Exception $e) {
                $errors[] = 'Error: ' . $e->getMessage();
            }
        }
        return $this->response->renderView("productes-store", "default", compact('errors', 'title','categories'));
    }

    public function delete(int $id): string
    {
/*        if (!Security::isAuthenticatedUser())
            App::get(Router::class)->redirect('login');*/

        $errors = [];
        $producte = null;
        $producteModel = App::getModel(producteModel::class);

        if (empty($id)) {
            $errors[] = '404 Not Found';
        } else {
            try {
                $producte = $producteModel->find($id);
            } catch (NotFoundException $e) {
                $errors[] = '404 Movie Not Found';
            }
        }

        $router = App::get(Router::class);
        $productePath = App::get("config")["productes_path"];

        return $this->response->renderView("productes-delete", "default", compact(
            "errors", "producte", 'productePath', 'router'));
    }

    public function destroy(): string
    {
        $errors = [];
        $producteModel = App::getModel(producteModel::class);

        $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
        if (empty($id)) {
            $errors[] = '404 Not Found';
        } else {
            $producte = $producteModel->find($id);
        }
        $userAnswer = filter_input(INPUT_POST, "userAnswer");
        if ($userAnswer === 'yes') {
            if (empty($errors)) {
                try {
                    $producte = $producteModel->find($id);
                    $result = $producteModel->delete($producte);
                } catch (PDOException $e) {
                    $errors[] = "Error: " . $e->getMessage();
                }
            }
        }
        else
            App::get(Router::class)->redirect('productes');

        if (empty($errors))
            App::get(Router::class)->redirect('productes');
        else
            return $this->response->renderView("productes-destroy", "default",
                compact("errors", "producte"));
    }

    public function filter(): string
    {

        $categoriaModel = App::getModel(categoriaModel::class);
        $categories = $categoriaModel->findAll();
        $router = App::get(Router::class);
        $title = "Productes - Agrow";
        $errors = [];
        $user =App::get("user");
        $id = $user->getId();


        $text = filter_input(INPUT_POST, "text", FILTER_SANITIZE_STRING);

        $tipo_busqueda = filter_input(INPUT_POST, "optradio", FILTER_SANITIZE_STRING);

        if (!empty($text) || $tipo_busqueda == "cat") {


            $pdo = App::get("DB");
            $producteModel = new producteModel($pdo);

            //En el cas de que siga administrador ha de ser un query diferent
            if($_SESSION["role"] == "ROLE_ADMIN" || $_SESSION["role"] == "ROLE_SUPERADMIN"){

                if ($tipo_busqueda == "nom") {
                    $productes = $producteModel->executeQuery("SELECT * FROM producte WHERE nom LIKE :text",
                        ["text" => "%$text%"]);

                }
                if ($tipo_busqueda == "descripcio") {
                    $productes = $producteModel->executeQuery("SELECT * FROM producte WHERE descripcio LIKE :text",
                        ["text" => "%$text%"]);

                }
                if ($tipo_busqueda == "cat") {
                    $tipusCategoria = filter_input(INPUT_POST, "catsel", FILTER_SANITIZE_STRING);
                    $productes = $producteModel->executeQuery("SELECT * FROM producte WHERE categoria_id LIKE :tipusCategoria",
                        ["tipusCategoria" => "%$tipusCategoria%"]);

                }

            }else{

                if ($tipo_busqueda == "nom") {
                    $productes = $producteModel->executeQuery("SELECT * FROM producte WHERE nom LIKE :text AND usuari_id LIKE :id",
                        ["text" => "%$text%",
                            "id"=>"%$id%"]);

                }
                if ($tipo_busqueda == "descripcio") {
                    $productes = $producteModel->executeQuery("SELECT * FROM producte WHERE descripcio LIKE :text AND usuari_id LIKE :id",
                        ["text" => "%$text%",
                            "id"=>"%$id%"]);

                }
                if ($tipo_busqueda == "cat") {
                    $tipusCategoria = filter_input(INPUT_POST, "catsel", FILTER_SANITIZE_STRING);
                    $productes = $producteModel->executeQuery("SELECT * FROM producte WHERE categoria_id LIKE :tipusCategoria AND usuari_id LIKE :id",
                        ["tipusCategoria" => "%$tipusCategoria%",
                            "id"=>"%$id%"]);

                }


            }

        } else {


            $pdo = App::get("DB");
            $producteModel = new producteModel($pdo);


            if($_SESSION["role"]=="ROLE_ADMIN" || $_SESSION["role"] == "ROLE_SUPERADMIN"){

                $productes = $producteModel->executeQuery("SELECT * FROM producte");

            }else{

                $productes = $producteModel->executeQuery("SELECT * FROM producte WHERE usuari_id LIKE :id",[
                    "id"=>"%$id%"]);



            }


            $errors[] = "Cal introduir una paraula de búsqueda o marcar la categoria";

        }

        if(empty($productes)){

            $errors[] = "No s'ha trobat cap producte";
        }

        return $this->response->renderView("productes", "default", compact('title', 'productes',
            'producteModel', 'errors',"router",'categories'));
    }


    public function edit(int $id)
    {

        //TODO:Arreglar edit com en partners de movieFX


        $isGetMethod = true;
        $errors = [];
        $producteModel = new producteModel(App::get("DB"));

        $categoriaModel = new categoriaModel(App::get("DB"));
        $categories = $categoriaModel->findAll(["nom" => "ASC"]);

        if (empty($id)) {
            $errors[] = '404 Not Found';
        } else {
            $producte = $producteModel->find($id);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $isGetMethod = false;

            $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
            if (empty($id)) {
                $errors[] = "Wrong ID";
            }

            $nom = filter_input(INPUT_POST, "nom", FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            if (empty($nom)) {
                $errors[] = "The nom is mandatory";
            }

            $descripcio = filter_input(INPUT_POST, "descripcio", FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            if (empty($descripcio)) {
                $errors[] = "The descripcio is mandatory";
            }

            $preu = filter_input(INPUT_POST, "preu", FILTER_VALIDATE_INT);
            $usuari_id = filter_input(INPUT_POST, "usuari_id", FILTER_VALIDATE_INT);
            $categoria_id = filter_input(INPUT_POST, "categoria", FILTER_VALIDATE_INT);



            /*$releaseDate = DateTime::createFromFormat("Y-m-d", $_POST["release_date"]);*/
          /*  if (empty($releaseDate)) {
                $errors[] = "The release date is mandatory";
            }*/

            if (empty($errors)) {
                //Si no se sube una imagen cogera la que tenemos en el formulario oculta
                $poster = filter_input(INPUT_POST, "poster");
                //Gestion de la imagen si se ha subido
                try {
                    $image = new UploadedFile('poster', 2000 * 1024, ['image/jpg', 'image/jpeg',"image/png"]);
                    if ($image->validate()) {
                        $image->save(Producte::POSTER_PATH);
                        $poster = $image->getFileName();
                    }
                    //Al estar editando no nos interesa que se muestre este error ya que puede ser que no suba archivo
                } catch (UploadedFileNoFileException $uploadFileNoFileException) {
                    //$errors[] = $uploadFileNoFileException->getMessage();
                } catch (UploadedFileException $uploadFileException) {
                    $errors[] = $uploadFileException->getMessage();
                }
            }

            if (empty($errors)) {
                try {
                    // Instead of creating a new object we load the current data object.
                    $producte = $producteModel->find($id);

                    //then we set the new values
                    $producte->setNom($nom);
                    $producte->setDescripcio($descripcio);
                    $producte->setPreu($preu);
                    $producte->setPoster($poster);
                    $producte->setUsuariId($usuari_id);
                    $producte->setCategoriaId($categoria_id);

                    $producteModel->update($producte);

                } catch (PDOException $e) {
                    $errors[] = "Error: " . $e->getMessage();
                }
            }
        }

        return $this->response->renderView("productes-edit", "default", compact("isGetMethod",
            "errors", "producte","categories"));
    }

    public function show($id){

        $errors = [];

        if (!empty($id)) {
            try {

                $producteModel = new producteModel(App::get("DB"));
                $producte = $producteModel->find($id);

                $userModel =  App::getModel(UserModel::class);
                $user_id = $producte->getUsuariId();
                $user = $userModel->findOneBy(["id"=>$user_id]);



            } catch (NotFoundException $notFoundException) {
                $errors[] = $notFoundException->getMessage();
            }
        }
        return $this->response->renderView("single-page", "default", compact(
            "errors", "producte","user"));

    }
    public function cesta($id){

        $errors = [];

        $user = App::get("user");
        $id_user = $user->getId();



        $producteModel = new producteModel(App::get("DB"));
        if(!empty($id)){

            $producte = $producteModel->find($id);
            $userModel =  App::getModel(UserModel::class);
            $user_id = $producte->getUsuariId();
            $user = $userModel->findOneBy(["id"=>$user_id]);

            if($producte->getUsuariId() == $id_user){

                App::get('flash')->set("message", "No pots comprar els teus propis productes");
            }else{

                $_SESSION["cesta"][] = $id;


                App::get('flash')->set("message", "Producte afegit correctament");
            }

        }else{

            $errors[] = "No s'ha trobat el producte";
        }


        //POSAR TAMBE UN FLAH MESSAGE
        return $this->response->renderView("single-page", "default", compact(
            "errors",'producte','user'));




    }
    public function mostrarCesta(){


        $cistella = $_SESSION["cesta"]??"";

        $cistellaProductes = [];

        $producteModel = new producteModel(App::get("DB"));

        if(empty($cistella)){

            App::get('flash')->set("message", "No hi ha cap producte en la cistella");

        }else{
            foreach ($cistella as $id){


                $producte = $producteModel->find($id);
                $cistellaProductes[] = $producte;

            }
        }





        return $this->response->renderView("cistella", "default", compact(
            "cistellaProductes"));



    }






}