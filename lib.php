<?php 

/* Implémentation de l'algorithme présenté dans l'article «Fast unfolding of communities in large networks» (http://arxiv.org/pdf/0803.0476v2.pdf), qui permet  de partitionner les noeuds d'un graphe de sorte à maximiser le nombre d'arêtes entre des noeuds qui appartiennent à un même groupe et à minimiser le nombre d'arêtes entre noeuds de groupes différents.

Par exemple, si les noeuds d'un graphe représentent des individus, et les arêtes entre deux noeuds indiquent que les individus
se connaissent, l'algorithme permet de déterminer les groupes de connaissances pour ces individus.

Pour un graphe dont les noeuds sont ainsi partitionnés, on peut calculer la "modularité" en sommant 
sur tous les couples de noeuds i et j la valeur 1/2m*(e(i,j) - k_i*k_j/2m)*d(i,j) où :
	- e(i,j) est le poids de l'arête qui relie les noeuds i et j (éventuellement 0 si ces noeuds ne sont pas reliés)
	- k_i est la somme des poids des arêtes adjacentes au noeud i
	- m est la somme de tous les poids des arêtes du graphe
	- d(i,j) vaut 1 si les noeuds i et j appartiennent à la même communauté, 0 sinon
	
La modularité est un nombre entre -1 et 1, et plus il est grand, plus le graphe vérifie la propriété voulue : les noeuds d'une même communauté sont très reliés entre eux, et peu reliés aux noeuds des autres communautés
	
L'algorithme démarre en créant autant de communautés que de noeuds, et en plaçant chaque noeud dans une communauté différente.
Puis on boucle sur les noeuds en ajoutant chaque noeud dans la communauté qui fait le plus augmenter la modularité.

Plus d'informations : http://arxiv.org/pdf/0803.0476v2.pdf
*/

class Node {
	//Cette classe implémente un noeud de graphe
	
	private $id; //un identifiant
	private $value; //ce que représente le noeud
	private $neigbours; //tableau d'éléments (neighbour, weight) où neighbour est un voisin du noeud, et weight le poids de l'arête qui relie les deux noeuds
	private $community; //on partitionne l'ensemble des noeuds en groupes (communautés). $community est le groupe dans lequel se trouve le noeud
	private $outward_weight; //la somme des poids des arêtes qui partent du noeud
	
	public function __construct($id, $value) {
		$this->id = $id;
		$this->value = $value;
		$this->neighbours = array();
		$this->outward_weight = 0;
	}
	
	public function __toString() {
		$neighbours_list = '';
		foreach($this->neighbours as $neighbour) {
			$neighbours_list = $neighbours_list. ", (id : "	.$neighbour[0]->getId()." , poids de l'arête : ".$neighbour[1].")";
		}
		$community = "</br>Communauté : ".(isset($this->community) ? $this->community->getId() : "Pas de communauté")."</br></br>";
		return "Noeud : ".$this->id."</br>Valeur : ".$this->value."</br>Voisins : ".$neighbours_list.$community;
	}
	
	public function getId() {
		return $this->id;
	}
	
	public function getValue(){
		return $this->value;
	}
	
	public function getNeighbours(){
		return $this->neighbours;
	}
	
	public function getCommunity(){
		return $this->community;
	}
	
	public function getOutwardWeight() {
		return $this->outward_weight;
	}
	
	public function setCommunity($community) {
		$this->community = $community;
	}
	
	public function addNeighbour($neighbour, $weight) {
		array_push($this->neighbours, array($neighbour, $weight));
		$this->outward_weight += $weight;
	}
	
	public function deltaModularityRemove() {
		//renvoie la variation de modularité si le noeud est retiré de sa communauté
		if (!$this->community) {
			throw new Exception("Le noeud ne possède pas de communauté, on ne peut pas le retirer de sa communauté");
		}
		
		//si le noeud est seul dans sa communauté, la variation vaut 0
		if (count($this->community->getNodes()) == 1) {
			return 0;
		}
		
		return -$this->community->weightToNode($this) + 1/(2*$this->community->getGraph()->getTotalWeight()) * ($this->outward_weight) * ($this->community->getSumOutwardWeight() - ($this->outward_weight));
		
	}
	
	public function deltaModularityAdd($community) {
		//renvoie la variation de modularité si le noeud est ajouté à la communauté $community
		if ($this->community->getId()  == $community->getId()) { //si le noeud est déjà dans la communauté, la variation est nulle
			return 0;
		}
		
		return $community->weightToNode($this) - 1/(2*$this->community->getGraph()->getTotalWeight()) * ($this->outward_weight) * ($community->getSumOutwardWeight()); //il faudrait diviser ce résultat par $this->graph->getTotalWeight() pour avoir la vraie variation de modularité, 
		//mais on n'en a pas besoin
		
	}	
	
	public function moveToBestCommunity() {
		//Explore les voisins du noeud et détermine la communauté du voisin qui fait le plus augmenter la modularité si le noeud est 
		//déplacé vers cette communauté.
		//Dans le cas où la variation de modularité correspondante est positive, le noeud est déplacé dans la communauté et la fonction
		//renvoie true. Dans le cas où la variation de modularité est négative, le noeud n'est pas déplacé et la fonction renvoit false.
		
		$best_community = null;
		$max_modularity_variation = 0;
		$dmr = $this->deltaModularityRemove();
		
		foreach ($this->neighbours as $neighbour) {
			if ($dmr + $this->deltaModularityAdd($neighbour[0]->getCommunity()) > $max_modularity_variation) {
				$max_modularity_variation = $dmr + $this->deltaModularityAdd($neighbour[0]->getCommunity());
				$best_community = $neighbour[0]->getCommunity();
			}
		}

		if ($max_modularity_variation > 0) {
			$this->community->removeNode($this);
			$best_community->addNode($this);

			return true;
			
		} else {
			return false;
		}	
	}
			
}

class Graph {
	//Cette classe implémente un graphe non-orienté valué
	private $nodes; //les noeuds du graphe
	private $total_weight; //la somme de tous les poids des arêtes du graphe
	private $communities; //le tableau des communautés  qui partitionnent le graphe
	private $current_community_id; //un compteur qu'on incrémente à chaque création de nouvelle communauté 
	
	public function __construct($file_path = '') {
	
				$this->nodes = array();
				$this->total_weight = 0;
				$this->communities = array();
				$this->current_community_id = 0;
	
		if ($file_path != ''){
			try { 
				$file = fopen($file_path, 'r');
			} catch (Exception $e)	{
				die("Exception : ". $e->getMessage(). "\n");
			}
			
			while($file_line = fgets($file)) {
				$line = explode(" ", $file_line);
				$id_node_1 = (int) $line[0];
				$id_node_2 = (int) $line[1];
				$weight = (int) $line[2];
				if (!isset($this->nodes[$id_node_1])) {
					$node_1 = $this->nodes[$id_node_1] = new Node($id_node_1, $id_node_1);
					$this->addCommunity()->addNode($node_1);
				}
				if (!isset($this->nodes[$id_node_2])) {
					$node_2 = $this->nodes[$id_node_2] = new Node($id_node_2, $id_node_2);
					$this->addCommunity()->addNode($node_2);
				}

				$this->addEdge($this->nodes[$id_node_1], $this->nodes[$id_node_2], $weight);
			}
			
			fclose($file);
			
		}
				
	}	
	
	public function __toString() {
		$result = "";
		foreach($this->nodes as $node){
			$result = $result.(string) $node;
		}
		return $result;
	} 
	
	public function getNodes() {
		return $this->nodes;
	}
	
	public function getTotalWeight() {
		return $this->total_weight;
	}
	
	public function getCommunities() {
		return $this->communities;
	}
	
	public function addNode($node) {
		array_push($this->nodes, $node);
		return $node;
	}
	
	public function addCommunity() {
		$new_community = new Community($this->current_community_id, $this);
		array_push($this->communities, $new_community);
		$this->current_community_id++;
		return $new_community;
	}
	
	public function addEdge($node1, $node2, $weight) {
		//ajoute entre les noeuds $node1 et $node2 une arête valuée à $weight
		if ( !(in_array($node1, $this->nodes)) || !(in_array($node2, $this->nodes)) ) {
			throw new Exception("On ne peut pas ajouter d'arête car un des noeuds n'appartient pas au graphe");
		}
		
		$node1->addNeighbour($node2, $weight);
		
		$community1 = $node1->getCommunity();
		$community2 = $node2->getCommunity();
		
		//si $node1 et $node2 appartiennent à des communautés, il faut mettre à jour leur sumOutwardWeight
		if ($community1) {
			$community1->addOutwardWeight($weight);
		}	
		
		if ($node1->getId() != $node2->getId()) {
		//si on ajoute une arête d'un noeud vers lui-même, on ne doit l'ajouter qu'une seule fois à la liste de ses voisins
			$node2->addNeighbour($node1, $weight);
			
			if ($community2) {
				$community2->addOutwardWeight($weight);
			}
		}
		
		//Si $node1 et $node2 appartiennent à la même communauté, on met le sumInsideWeight de la communauté à jour
		if ($community1 && $community2 && ($community1->getId() == $community2->getId())) {
			$community1->addInsideWeight($weight);
		}
		
		$this->total_weight += $weight;
	}
	
	public function findCommunities() {
		//Algorithme qui parcourt en boucle les noeuds en les plaçant à chaque fois dans la meilleure communauté, et s'arrête quand
		//aucun amélioration n'est plus possible
		
		$nb_loop = 0; //le nombre de fois qu'on a parcouru tous les noeuds
		
		do {
			$modif = false; //booléen qui indique si un noeud a été bougé lors du parcours
			foreach($this->nodes as $node) {
				if ($node->moveToBestCommunity()) {
					$modif = true;
				}
	
			}
			
			$nb_loop++;
		} while ($modif);
		
		//il faut maintenant supprimmer les communautés vides et réindexer les communautés restantes
		
		foreach ($this->communities as $community) {
			if ($community->isEmpty()) {
				unset($this->communities[$community->getId()]);
			}
		}	
		
		$this->communities = array_values($this->communities);
		
		for ($i = 0; $i < count($this->communities); $i++){
			$this->communities[$i]->setId($i);			
		}
		
		return $nb_loop;
				
	}
	
	public function communitiesGraph() {
		//Renvoie le graph des communautés, c'est à dire un nouveau graphe dont les noeuds représentent les communautés du graph actuel
		//Dans le nouveau graphe, le poids d'une arête entre deux noeuds (qui représentent les communautés c1 et c2) est égal à la somme
		// des poids des arêtes qui relient un noeud de c1 à un noeud de c2 dans l'ancien graphe. Le nouveau graphe peut posséder des arêtes
		//qui relient un noeud à lui-même.
		
		$new_graph = new Graph();
		
		foreach($this->communities as $community) {
			$new_node = $new_graph->addNode(new Node($community->getId(), $community->getNodesValue()));
			$new_graph->addCommunity()->addNode($new_node);
		}
		
		for($i = 0, $c = count($this->communities); $i < $c; $i++) {
			$community1 = $this->communities[$i];
			$community_1_id = $community1->getId();
			
			for($j = $i; $j < $c; $j++) {
				$community2 = $this->communities[$j];
				$community_2_id = $community2->getId();
				
				$weight = $community1->weightToCommunity($community2);
				
				if($weight > 0) {
					$new_graph->addEdge($new_graph->getNodes()[$community_1_id], $new_graph->getNodes()[$community_2_id], $weight);
				}
			}
		}
		
		return $new_graph;
	}
	
}

class Community {
	//Cette classe implémente un groupe de noeuds dans un graphe
	
	private $id; //un identifiant qui permet de tester l'égalité de deux communautés en comparant des entiers plutôt que des objets 
	private $graph; //le graphe qui contient la communauté
	private $nodes; //les noeuds contenus dans la communauté
	private $sum_outward_weight; //la somme de tous les poids des arêtes adjacentes à un sommet de la communauté
	private $sum_inside_weight; //la somme des poids des arêtes qui relient deux noeuds de la communauté
	
	public function __construct($id, $graph) {
		$this->id = $id;
		$this->graph = $graph;
		$this->nodes = array();
		$this->sum_outward_weight = 0;		
		$this->sum_inside_weight = 0;
	}
	
	public function __toString(){
		$nodes = "";
		foreach($this->nodes as $node) {
			$nodes = $nodes.", ".$node->getId();
		}
		return "Communauté ".$this->id."</br>Noeuds : ".$nodes."</br></br>";
	}
	
	public function getId() {
		return (int) $this->id;
	}
	
	public function getGraph() {
		return $this->graph;
	}
	
	public function getNodes() {
		return $this->nodes;
	}
	
	public function getNodesValue() {
		//renvoie la chaîne concaténée des valeurs des noeuds
		$values = "[";
		foreach($this->nodes as $node) {
			$values = $values.$node->getValue().",";
		}
		
		return $values."]";
	} 
	
	public function getSumOutwardWeight() {
		return $this->sum_outward_weight;
	}
	
	public function getSumInsideWeight() {
		return $this->sum_inside_weight;
	}
	
	public function addInsideWeight($weight) {
		$this->sum_inside_weight += $weight;
	}
	
	public function addOutwardWeight($weight) {
		$this->sum_outward_weight += $weight;
	}
	
	public function setId($new_id) {
		$this->id = $new_id;
	}
	
	public function isEmpty() {
		return (count($this->nodes) == 0);
	}
	
	public function addNode($node) {
		$node->setCommunity($this);
		$this->nodes[$node->getId()] = $node;
		
		//pour mettre à jour sum_outward_weight, il faut lui ajouter le poids des arêtes issues de $node 
		$this->sum_outward_weight += $node->getOutwardWeight();
				
		//pour mettre à jour sum_inside_weight, il faut lui ajouter le poids des arêtes qui joignent $node à la communauté
		$this->sum_inside_weight += $this->weightToNode($node);
		
	}
	
	public function removeNode($node) {
		try {
			unset($this->nodes[$node->getId()]);
		} catch (Exception $e) {
			print ("Exception : ".$e->getMessage());
		}
		
		$this->sum_inside_weight += $this->weightToNode($node);
		$this->sum_outward_weight -= $node->getOutwardWeight();
		$node->setCommunity(null);
	}
	
	public function weightToNode($node) {
		//renvoie la somme des poids des arêtes qui relient le noeud $node à un noeud de la communauté
		$result = 0;
		foreach($node->getNeighbours() as $neighbour){
			//$neighbour[0] est le noeud voisin, $neighbour[1] est le poids de l'arête qui relie $node à $neigbour[0]
			if ($neighbour[0]->getCommunity() && $neighbour[0]->getCommunity()->getId() == $this->id) {
				$result += $neighbour[1];
			}
		}
		
		return $result;
	}
	
	public function weightToCommunity($community) {
		//renvoie la somme des poids des arêtes qui relient un noeud de la communauté $this à un noeud de la communauté $community
		
		$result = 0;
		
		if ($this->id != $community->getId()) { //les deux communautés ne sont pas les mêmes
			foreach($this->nodes as $node) {
				$result += $community->weightToNode($node);
			}
		} else {
			$result = $this->sum_inside_weight;	
		}
		
		return $result;
	}
}

function algo($file_path) {

	$data = json_decode(file_get_contents('community.json'));

	$keywords = array();

	$count = 0;
	foreach($data as $article) {
		foreach($article->connected_keywords as $word) {
			if (!isset($keywords[$word->word_title]) && $word->word_title != "Politique") {
				$keywords[$word->word_title] = $count;
				$count++;
			}
		}
	
	}
	
	$graph = new Graph();
	
	foreach($keywords as $keyword => $id) {
		$node = $graph->addNode(new Node($id, $keyword));
		$graph->addCommunity()->addNode($node);
	}
	
	print $graph;
	$coocurrence = array();
	
	for($i = 0; $i < count($keywords);$i++) {
			for($j = 0; $j < count($keywords);$j++) {
				$coocurrence[$i][$j] = 0;
			}
	}
	
	foreach($data as $article) {
		for($i = 0; $i < count($article->connected_keywords);$i++) {
			if ($article->connected_keywords[$i]->word_title != "Politique") {
				for($j = $i+1; $j < count($article->connected_keywords);$j++) {
					if ($article->connected_keywords[$j]->word_title != "Politique") {
						$coocurrence[$keywords[$article->connected_keywords[$i]->word_title]][$keywords[$article->connected_keywords[$j]->word_title]] += 1; 
					}
				}
			}
		}
	}
	
	for($i = 0; $i < count($keywords);$i++) {
		for($j = $i+1; $j < count($keywords);$j++) {
			if ($coocurrence[$i][$j] > 0) {
				$graph->addEdge($graph->getNodes()[$i], $graph->getNodes()[$j], $coocurrence[$i][$j]);
			}
		}
	}
	
	
	$continue = true;
	while($continue) {
		if ($graph->findCommunities() == 1) {//modifie graphe en plaçant chaque noeud dans la bonne communauté, et renvoie le nombre de
		//parcours effectué. Quand ce nombre vaut 1, l'algorithme ne peut plus progresser et termine.
			$continue = false;
			break;
		} 

		//$graph = $graph->communitiesGraph();
	}

	print $graph->communitiesGraph();

	
}

$g = new Graph();
$n1 = new Node(1, 'Noeud 1');
$n2 = new Node(2, 'Noeud 2');
$n3 = new Node(3, 'Noeud 3');
$n4 = new Node(4, 'Noeud 4');
$n5 = new Node(5, 'Noeud 5');
$g->addNode($n1);
$g->addNode($n2);
$g->addNode($n3);
$g->addNode($n4);
$g->addNode($n5);
$g->addEdge($n1, $n2, 1);
$g->addEdge($n1, $n1, 10);
$g->addEdge($n2, $n3, 2);
$g->addEdge($n1, $n3, 3);
$g->addEdge($n4, $n5, 4);
$community1 = $g->addCommunity();
$community1->addNode($n1);
$community1->addNode($n2);
$community2 = $g->addCommunity();
$community2->addNode($n3);
$community3 = $g->addCommunity();
$community3->addNode($n4);
$community3->addNode($n5);

//print $g->getCommunities()[0]->getSumInsideWeight();


set_time_limit(3600);
algo('graph2.txt');


/*$node0 = $g2->getNodes()[0];
$n4 = $g2->getNodes()[4];
print (string) $n4;
$comm = $node0->getCommunity();
print count($comm->getNodes());
$node0->moveToBestCommunity();
print $node0->deltaModularityRemove();*/

?>
	
	
